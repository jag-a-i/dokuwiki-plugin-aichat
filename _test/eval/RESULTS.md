# Model evaluation results

| Model | Status |
|---|---|
| Current: Gemma 4B (exact id from your llama-swap config) | **NOT RUN** - endpoint not accessible from the build environment and live workload not authorized |
| Candidate: larger local model (e.g. Qwen 35B-class MoE, id to be supplied) | **NOT RUN** - model id not supplied, endpoint not accessible |

Only the runner itself was validated with scripted models (these numbers say nothing about any real model):

- `aichat_eval --dry-run oracle --repeat 2`: every correctness metric 100 % (e.g. clarification 6/6,
  follow-up 4/4, abstention 6/6, inappropriate clarification 0/20) -> fixtures and scoring are consistent.
- `aichat_eval --dry-run naive` (always answers from the first excerpt): clarification 0/3, follow-up 0/2,
  abstention 2/3, direct answer 3/5 -> the metrics detect a model that ignores the policy.

To run live (sends only the synthetic fixture texts to the chat endpoint configured in the wiki):
```bash
cp lib/plugins/aichat/_test/eval/eval.config.example.json /secure/place/eval.config.json   # set real model ids
php bin/plugin.php aichat_eval --config /secure/place/eval.config.json --repeat 3 --yes --out eval-results
```
Report sample size (cases x repeats), hardware, endpoint configuration and timing boundaries with any result.
Latency is measured per turn inside the PHP process and includes all model calls of that turn.
Do not infer speed from parameter counts.
