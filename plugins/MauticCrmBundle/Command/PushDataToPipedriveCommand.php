<?php

declare(strict_types=1);

namespace MauticPlugin\MauticCrmBundle\Command;

use Doctrine\ORM\EntityManager;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticCrmBundle\Integration\Pipedrive\Export\CompanyExport;
use MauticPlugin\MauticCrmBundle\Integration\Pipedrive\Export\LeadExport;
use MauticPlugin\MauticCrmBundle\Integration\PipedriveIntegration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PushDataToPipedriveCommand extends Command
{
    private SymfonyStyle $io;
    private IntegrationHelper $integrationHelper;
    private Translator $translator;
    private EntityManager $entityManager;
    private CompanyExport $companyExport;
    private LeadExport $leadExport;

    public function __construct(
        IntegrationHelper $integrationHelper,
        Translator $translator,
        EntityManager $entityManager,
        CompanyExport $companyExport,
        LeadExport $leadExport
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->translator        = $translator;
        $this->entityManager     = $entityManager;
        $this->companyExport     = $companyExport;
        $this->leadExport        = $leadExport;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('mautic:integration:pipedrive:push')
            ->setDescription('Pushes the data from Mautic to Pipedrive')
            ->addOption(
                '--restart',
                null,
                InputOption::VALUE_NONE,
                'Restart intgeration'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var PipeDriveIntegration $integrationObject */
        $integrationObject = $this->integrationHelper
            ->getIntegrationObject(PipedriveIntegration::INTEGRATION_NAME);
        $this->io          = new SymfonyStyle($input, $output);

        $pushed = 0;

        if (!$integrationObject || !$integrationObject->getIntegrationSettings()->getIsPublished()) {
            $this->io->note('Pipedrive integration is disabled.');

            return 0;
        }

        if ($input->getOption('restart')) {
            $this->io->note(
                $this->translator->trans(
                    'mautic.plugin.config.integration.restarted',
                    ['%integration%' => $integrationObject->getName()]
                )
            );
            $integrationObject->removeIntegrationEntities();
        }

        if ($integrationObject->isCompanySupportEnabled()) {
            $this->io->title('Pushing Companies');
            $this->companyExport->setIntegration($integrationObject);

            $companies = $this->entityManager->getRepository(Company::class)->findAll();
            foreach ($companies as $company) {
                if ($this->companyExport->pushCompany($company)) {
                    ++$pushed;
                }
            }
            $this->io->text('Pushed '.$pushed);
        }

        $leads = $this->entityManager->getRepository(Lead::class)->findAll();
        $this->io->title('Pushing Leads');
        $this->leadExport->setIntegration($integrationObject);
        $pushed = 0;
        foreach ($leads as $lead) {
            if ($this->leadExport->create($lead)) {
                ++$pushed;
            }
        }
        $this->io->text('Pushed '.$pushed);

        $this->io->success('Execution time: '.number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3));

        return 0;
    }
}
