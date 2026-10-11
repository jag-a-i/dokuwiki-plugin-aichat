<?php

use dokuwiki\plugin\aichat\AbstractCLI;
use dokuwiki\plugin\aichat\Telemetry\ExporterFactory;
use dokuwiki\plugin\aichat\Telemetry\Preflight;
use splitbrain\phpcli\Options;

/**
 * bin/plugin.php aichat_telemetry --yes
 * Sends one synthetic, metadata-only trace to the configured backend and explains the response.
 */
class cli_plugin_aichat_telemetry extends AbstractCLI
{
    /** @inheritdoc */
    protected function setup(Options $options)
    {
        parent::setup($options);
        $options->setHelp('Preflight: send ONE synthetic metadata-only trace to the configured telemetry endpoint and explain the result.');
        $options->registerOption('yes', 'Confirm sending the synthetic trace to the configured endpoint', 'y');
    }

    /** @inheritdoc */
    protected function main(Options $options)
    {
        parent::main($options);
        if (!$options->getOpt('yes')) {
            $this->error('Not confirmed. This sends one synthetic trace (no wiki content) to the configured endpoint. Re-run with --yes.');
            exit(2);
        }
        $r = Preflight::run($this->helper->getPluginConf(), ExporterFactory::dokuHttp());
        if ($r['ok']) {
            $this->success($r['message']);
            exit(0);
        }
        $this->error($r['message']);
        exit(1);
    }
}
