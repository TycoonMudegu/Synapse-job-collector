# Synapse job collector

A small daily collector deployed independently from the PHP application to **Cloud Run Jobs**. It reuses Synapse's PHP parsers and the existing Node/Playwright Fuzu reader, without application dependencies or database credentials. The initial supported connectors are MyJobMag, BrighterMonday, Fuzu, Talent.com, Indeed through SerpApi Google Jobs, Corporate Staffing, structured JSON and RSS/Atom with structured vacancy detail pages.

The default `http` container has no browser. The optional `browser` target adds Chromium for Fuzu. Browsers start only while that source is being collected. There is no web server or always-on instance.

## Flow

1. Cloud Scheduler starts the job at **07:00 Africa/Nairobi**, or Synapse's admin requests a manual execution.
2. The worker authenticates to Synapse and claims a run. Synapse returns its enabled source configuration and a run-specific token.
3. Sources are collected sequentially, with per-source caps and existing robots, TLS, public DNS and no-redirect safeguards.
4. Structured vacancies are posted in stable, bounded batches. Synapse validates, deduplicates and stores them in the shared catalogue.
5. The worker reports each source's outcome and exits. A failed source does not prevent collecting the others.

The collector never receives user profiles, CVs, Gmail tokens or application credentials. It cannot approve jobs or send applications. Account login, CAPTCHA handling, proxy rotation and automated job applications are not implemented.

## Local verification

PHP 8.3+ needs curl, DOM, mbstring and SimpleXML. No Composer dependencies are installed at runtime.

```bash
php tests/run.php
php tests/url-guard.php
python3 -m unittest discover -s tests -p deployment.py
bash -n deploy.sh
docker build --target http -t synapse-job-collector .
```

The image runs the same fixture tests during its build. Tests do not contact live job sites, Synapse or paid providers.

To perform an explicitly intended local collection, export the variables in `.env.example`, using the real public Synapse HTTPS URL and its configured secret, then run `php scripts/collect.php`. `.env` is not loaded automatically; secrets stay in the process environment or Secret Manager. Local runs write real catalogue data and consume the same daily allowances as cloud executions.

## Application prerequisites

In the Synapse repository, apply migration `012_job_collector.sql`, grant your existing account using `php scripts/admin.php grant --email=owner@example.com`, and configure `COLLECTOR_TOKEN`, `COLLECTOR_PROJECT`, `COLLECTOR_REGION` and `COLLECTOR_JOB` in the PHP host's private environment. Its normal Google Application Default Credentials identity needs `roles/run.jobsExecutorWithOverrides` on this job for admin-triggered runs. After the first successful import, set `COLLECTOR_ENABLED=true` so personal discovery searches stored vacancies instead of repeatedly visiting the sources. See `../Synapse/docs/job-collector.md`.

The initial enabled source is MyJobMag's advertised feed. Enable other sources from Synapse's Sources tab after configuring their access and permissions. Existing public-page parsers currently use technology-category URLs. New website layouts still need adapter code. The source snapshot is authoritative: no URLs or commands are accepted from browser users as collection instructions.

## Google Cloud deployment

Deploy this collector as a **Cloud Run Job**. The Cloud Run service console's **Connect repository** workflow generates a service deployment that waits for a web server on `PORT=8080`. This container runs a batch task and exits, so that deployment fails its startup check. Use `deploy.sh` below, or choose **Cloud Run → Jobs → Deploy container** with an image that has already been built. The script also configures the runtime identity, token access and daily scheduler.

The script creates or updates a job, one scheduler entry, a container repository and dedicated service identities. It does **not** start an immediate collection. Deployment requires your authenticated Google Cloud CLI, a billing-enabled project and the relevant IAM permissions. The build account must be allowed to push images to Artifact Registry.

Generate a private token file without a final newline, put the same value in the PHP host, then deploy:

```bash
umask 077
php -r 'echo bin2hex(random_bytes(32));' > /private/path/collector-token
PROJECT_ID=your-project \
REGION=us-central1 \
SYNAPSE_URL=https://your-synapse-host.example \
COLLECTOR_TOKEN_FILE=/private/path/collector-token \
ADMIN_INVOKER_EMAIL=your-php-service-account@your-project.iam.gserviceaccount.com \
bash deploy.sh
```

`deploy.sh` preserves an existing token secret. Supplying a different local token file will not rotate it. Rotate credentials deliberately in Secret Manager and the PHP environment together. Never commit a token file or service-account key.

If the build succeeded and a service deployment failed, reuse its image by setting `COLLECTOR_IMAGE` to the Artifact Registry image URL with its tag or digest. With the deployment variables above exported, run:

```bash
COLLECTOR_IMAGE=us-central1-docker.pkg.dev/your-project/your-repository/your-image:existing-tag \
IMAGE_TARGET=browser \
SCHEDULER_PAUSED=true \
bash deploy.sh
```

This skips Cloud Build and image-repository creation while configuring the job and its permissions. Match `IMAGE_TARGET` to the existing image so the script allocates the appropriate memory. `SCHEDULER_PAUSED=true` leaves the daily schedule paused for application verification; resume it in Cloud Scheduler when ready. The service's generated repository trigger still targets a service, so disable or reconfigure that trigger before pushing further changes.

Fresh builds install system packages and compile PHP extensions. The browser image also installs Chromium, Xvfb and Playwright and runs browser fixture tests. These are substantial downloads and compilation steps. `deploy.sh` defaults to `IMAGE_TARGET=http`, which skips browser dependencies; use the browser image when Fuzu is enabled. A plain Docker build without `--target` selects the final `browser` stage. The current build config does not pull cached layers from previous builds, so fresh Cloud Build workers repeat that work. Reusing `COLLECTOR_IMAGE` avoids rebuilding when only the deployment configuration needs to change.

Use `IMAGE_TARGET=browser` to build Chromium support. It defaults to headless Chromium. For a permitted source that needs a visible browser, supply `FUZU_BROWSER_HEADLESS=false` when running `deploy.sh`; the browser image starts Xvfb for the process. Validate Fuzu from the chosen cloud region before relying on it. Security challenges and access denials are surfaced; no CAPTCHA solving or access-control bypass is supplied.

To enable Indeed, create a separate Secret Manager secret containing your SerpApi key and pass its name as `SERPAPI_SECRET`. The runtime identity gets access only to the configured collector secrets. Indeed coverage depends on Google's indexed jobs; the worker never visits Indeed or Google search pages. Paid-provider requests remain capped at two per source run. No API key is included in source snapshots, callback payloads or logs.

## Cost controls

- One task, parallelism one, one vCPU; 512 MiB for HTTP or 2 GiB for the browser image.
- Cloud Run hard timeout: twenty minutes; worker time budget: fifteen minutes; automatic task retries: zero.
- One daily scheduler entry with retries disabled. Manual runs require administrator permission and a server reservation.
- Synapse defaults to four total executions per EAT day, a five-minute cooldown and one active reservation. Replayed daily claims exit before requesting source pages.
- Per-source caps, one-second public-page spacing and at most twenty detail pages; MyJobMag can import up to one hundred structured feed entries.
- No LLM extraction, Cloud SQL, VPC connector, NAT gateway, proxy service, persistent browser profile or additional queue service.
- Callback retries use stable identities and do not repeat paid source searches. Edited/unpublished catalogue records stay under admin control.

Google free allowances are shared at billing-account level. Region, other workloads, image storage, builds, logging and internet egress affect actual cost. Browser images can exceed Artifact Registry's free storage allowance. Newly created container repositories receive a cleanup policy that removes images older than seven days while keeping the two most recent versions per package; existing repositories keep their current policies. No zero-cost guarantee is made. See [Cloud Run pricing](https://cloud.google.com/run/pricing), [Scheduler pricing](https://cloud.google.com/scheduler/pricing) and [Artifact Registry pricing](https://cloud.google.com/artifact-registry/pricing).

Copied collection code originates in `TycoonMudegu/Synapse`; it is maintained here independently. Do not copy the PHP app, `.env`, credentials, user records, documents or storage into this repository or its image.
