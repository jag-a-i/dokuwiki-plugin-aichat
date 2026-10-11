<?php

use dokuwiki\plugin\aichat\AbstractCLI;
use dokuwiki\plugin\aichat\Eval\EvalRunner;
use dokuwiki\plugin\aichat\Eval\ScriptedChat;
use splitbrain\phpcli\Options;

/**
 * Evaluate the clarification/answer policy of one or more chat models against labeled synthetic fixtures.
 *
 *   bin/plugin.php aichat_eval --dry-run oracle            # validate fixtures + scoring, no model, no network
 *   bin/plugin.php aichat_eval --config eval.config.json --yes --out results   # LIVE: calls the configured endpoint
 *
 * Live runs send ONLY the synthetic fixture texts to the chat endpoint already configured in the wiki
 * (generic_apiurl etc.); they require --yes. Retrieval is simulated from the fixtures.
 */
class cli_plugin_aichat_eval extends AbstractCLI
{
    /** @inheritdoc */
    protected function setup(Options $options)
    {
        parent::setup($options);
        $options->setHelp('Evaluate chat models against labeled synthetic fixtures (clarify / answer / abstain / follow-up).');
        $options->registerOption('fixtures', 'Fixture file (default: _test/eval/fixtures.json)', 'f', 'file');
        $options->registerOption('config', 'Model config JSON (see _test/eval/eval.config.example.json)', 'c', 'file');
        $options->registerOption('repeat', 'Repeat every case N times (overrides config)', 'r', 'N');
        $options->registerOption('out', 'Write <out>.json and <out>.md', 'o', 'prefix');
        $options->registerOption('dry-run', 'Use a scripted model instead of a real one: oracle | naive', 'd', 'mode');
        $options->registerOption('yes', 'Confirm a LIVE run against the configured endpoint', 'y');
    }

    /** @inheritdoc */
    protected function main(Options $options)
    {
        parent::main($options); // honours the common --lang / debug options
        auth_setup();
        $fixtures = json_decode((string)file_get_contents($options->getOpt('fixtures', __DIR__ . '/../_test/eval/fixtures.json')), true);
        $runner = new EvalRunner($fixtures, fn(array $v) => $this->helper->buildDecisionPrompt($v));
        $titles = [];
        foreach ($fixtures['corpora'] as $docs) foreach ($docs as $d) $titles[$d['page']] = $d['title'];

        $dry = (string)$options->getOpt('dry-run', '');
        $config = $options->getOpt('config') ? json_decode((string)file_get_contents($options->getOpt('config')), true) : [];
        $repeat = (int)($options->getOpt('repeat') ?: ($config['repeat'] ?? 1));
        $reports = [];

        if ($dry !== '') {
            if (!in_array($dry, ['oracle', 'naive'], true)) throw new \InvalidArgumentException('dry-run must be oracle or naive');
            $reports[] = $runner->run("scripted-$dry", fn() => new ScriptedChat($dry, $titles), $repeat,
                static function ($case, $ti, $turn, $chat) { if ($chat instanceof ScriptedChat) $chat->expect($turn['expect']); });
            $status = "DRY RUN with scripted '$dry' model - validates fixtures and scoring only, NOT a model evaluation";
        } else {
            if (empty($config['models'])) throw new \InvalidArgumentException('--config with models[] required for live runs');
            if (!$options->getOpt('yes')) {
                $this->error('Live run not confirmed. This would send the synthetic fixture texts to the configured chat endpoint. Re-run with --yes.');
                exit(2);
            }
            foreach ($config['models'] as $m) {
                if (str_contains((string)$m['chatmodel'], 'REPLACE-WITH')) throw new \InvalidArgumentException('placeholder model id in config');
                $this->helper->updateConfig(['chatmodel' => $m['chatmodel']]);
                $this->info('Running {label} ({model})', ['label' => $m['label'], 'model' => $m['chatmodel']]);
                $reports[] = $runner->run((string)$m['label'], fn() => $this->helper->factory->loadModel('chat', (string)$m['chatmodel']), $repeat);
            }
            $status = 'LIVE run on ' . gmdate('Y-m-d H:i') . ' UTC against the configured endpoint (synthetic fixtures, simulated retrieval)';
        }

        $md = EvalRunner::markdown($reports, $status);
        echo $md;
        if ($options->getOpt('out')) {
            file_put_contents($options->getOpt('out') . '.json', json_encode(['status' => $status, 'reports' => $reports], JSON_PRETTY_PRINT));
            file_put_contents($options->getOpt('out') . '.md', $md);
        }
        return 0;
    }
}
