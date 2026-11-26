#!/usr/bin/env bash
set -euo pipefail

# Run only after the PHP migration, callback token and admin role are configured.
: "${PROJECT_ID:?Set PROJECT_ID}"
: "${SYNAPSE_URL:?Set SYNAPSE_URL to the public HTTPS application origin}"
REGION="${REGION:-us-central1}"
JOB_NAME="${JOB_NAME:-synapse-job-collector}"
REPOSITORY="${REPOSITORY:-synapse-collectors}"
IMAGE_TARGET="${IMAGE_TARGET:-http}"
COLLECTOR_IMAGE="${COLLECTOR_IMAGE:-}"
TOKEN_SECRET="${TOKEN_SECRET:-synapse-collector-token}"
for collector_identifier in "$PROJECT_ID" "$REGION" "$JOB_NAME" "$REPOSITORY" "$TOKEN_SECRET"; do
    [[ "$collector_identifier" =~ ^[a-z][a-z0-9-]{0,62}$ ]] || { echo 'Invalid resource identifier.' >&2; exit 1; }
done
[[ "$SYNAPSE_URL" =~ ^https://[^,\?\#[:space:]]+$ ]] || { echo 'SYNAPSE_URL must be a public HTTPS origin or application path.' >&2; exit 1; }
[[ "$IMAGE_TARGET" == http || "$IMAGE_TARGET" == browser ]] || { echo 'IMAGE_TARGET must be http or browser.' >&2; exit 1; }
if [[ -n "${ADMIN_INVOKER_EMAIL:-}" ]]; then
    [[ "$ADMIN_INVOKER_EMAIL" =~ ^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.gserviceaccount\.com$ ]] || { echo 'Invalid admin service-account email.' >&2; exit 1; }
fi
if [[ -n "$COLLECTOR_IMAGE" ]]; then
    [[ "$COLLECTOR_IMAGE" =~ ^[a-z0-9.-]+-docker\.pkg\.dev/[a-z0-9._/-]+(:[a-zA-Z0-9._-]+|@sha256:[a-f0-9]{64})$ ]] || { echo 'COLLECTOR_IMAGE must be an Artifact Registry image with a tag or sha256 digest.' >&2; exit 1; }
fi
command -v gcloud >/dev/null || { echo 'Install and authenticate the Google Cloud CLI first.' >&2; exit 1; }
cd "$(dirname "$0")"
gcloud services enable run.googleapis.com cloudscheduler.googleapis.com artifactregistry.googleapis.com cloudbuild.googleapis.com secretmanager.googleapis.com --project="$PROJECT_ID" --quiet

if [[ -z "$COLLECTOR_IMAGE" ]] && ! gcloud artifacts repositories describe "$REPOSITORY" --location="$REGION" --project="$PROJECT_ID" >/dev/null 2>&1; then
    gcloud artifacts repositories create "$REPOSITORY" --repository-format=docker --location="$REGION" --project="$PROJECT_ID" --quiet
    gcloud artifacts repositories set-cleanup-policies "$REPOSITORY" --policy=deploy/cleanup-policy.json --no-dry-run --location="$REGION" --project="$PROJECT_ID" --quiet
fi
if ! gcloud secrets describe "$TOKEN_SECRET" --project="$PROJECT_ID" >/dev/null 2>&1; then
    : "${COLLECTOR_TOKEN_FILE:?Supply a private file containing the same token configured in Synapse}"
    php -r '$v = file_get_contents($argv[1]); if (strlen($v) < 32 || preg_match("/\\s/", $v)) exit(1);' "$COLLECTOR_TOKEN_FILE" || { echo 'Invalid token file: use at least 32 characters with no whitespace or final newline.' >&2; exit 1; }
    gcloud secrets create "$TOKEN_SECRET" --data-file="$COLLECTOR_TOKEN_FILE" --replication-policy=automatic --project="$PROJECT_ID" --quiet
fi

RUNTIME_ACCOUNT="synapse-collector-runtime@$PROJECT_ID.iam.gserviceaccount.com"
TRIGGER_ACCOUNT="synapse-collector-trigger@$PROJECT_ID.iam.gserviceaccount.com"
for collector_account in synapse-collector-runtime synapse-collector-trigger; do
    if ! gcloud iam service-accounts describe "$collector_account@$PROJECT_ID.iam.gserviceaccount.com" --project="$PROJECT_ID" >/dev/null 2>&1; then
        gcloud iam service-accounts create "$collector_account" --project="$PROJECT_ID" --quiet
    fi
done
gcloud secrets add-iam-policy-binding "$TOKEN_SECRET" --member="serviceAccount:$RUNTIME_ACCOUNT" --role=roles/secretmanager.secretAccessor --project="$PROJECT_ID" --quiet >/dev/null
if [[ -z "$COLLECTOR_IMAGE" ]]; then
    COLLECTOR_TAG="$(git rev-parse --short HEAD 2>/dev/null || date -u +%Y%m%d%H%M%S)"
    gcloud builds submit --config=cloudbuild.yaml --substitutions="_REGION=$REGION,_REPOSITORY=$REPOSITORY,_JOB=$JOB_NAME,_TAG=$COLLECTOR_TAG,_TARGET=$IMAGE_TARGET" --project="$PROJECT_ID" --quiet
    COLLECTOR_IMAGE="$REGION-docker.pkg.dev/$PROJECT_ID/$REPOSITORY/$JOB_NAME:$COLLECTOR_TAG"
fi

COLLECTOR_MEMORY=512Mi
if [[ "$IMAGE_TARGET" == browser ]]; then COLLECTOR_MEMORY=2Gi; fi
COLLECTOR_SECRETS="COLLECTOR_TOKEN=$TOKEN_SECRET:latest"
if [[ -n "${SERPAPI_SECRET:-}" ]]; then
    [[ "$SERPAPI_SECRET" =~ ^[a-z][a-z0-9-]{0,62}$ ]] || { echo 'Invalid SerpApi secret identifier.' >&2; exit 1; }
    gcloud secrets add-iam-policy-binding "$SERPAPI_SECRET" --member="serviceAccount:$RUNTIME_ACCOUNT" --role=roles/secretmanager.secretAccessor --project="$PROJECT_ID" --quiet >/dev/null
    COLLECTOR_SECRETS="$COLLECTOR_SECRETS,COLLECTOR_SERPAPI_KEY=$SERPAPI_SECRET:latest"
fi
gcloud run jobs deploy "$JOB_NAME" --image="$COLLECTOR_IMAGE" \
    --region="$REGION" --project="$PROJECT_ID" --service-account="$RUNTIME_ACCOUNT" \
    --tasks=1 --parallelism=1 --max-retries=0 --task-timeout=1200s --cpu=1 --memory="$COLLECTOR_MEMORY" \
    --set-env-vars="SYNAPSE_URL=$SYNAPSE_URL,COLLECTOR_MAX_SECONDS=900,FUZU_BROWSER_HEADLESS=${FUZU_BROWSER_HEADLESS:-true}" --set-secrets="$COLLECTOR_SECRETS" --quiet
gcloud run jobs add-iam-policy-binding "$JOB_NAME" --member="serviceAccount:$TRIGGER_ACCOUNT" --role=roles/run.invoker --region="$REGION" --project="$PROJECT_ID" --quiet >/dev/null
if [[ -n "${ADMIN_INVOKER_EMAIL:-}" ]]; then
    gcloud run jobs add-iam-policy-binding "$JOB_NAME" --member="serviceAccount:$ADMIN_INVOKER_EMAIL" --role=roles/run.jobsExecutorWithOverrides --region="$REGION" --project="$PROJECT_ID" --quiet >/dev/null
fi

COLLECTOR_SCHEDULE_ACTION=create
if gcloud scheduler jobs describe "$JOB_NAME-daily" --location="$REGION" --project="$PROJECT_ID" >/dev/null 2>&1; then COLLECTOR_SCHEDULE_ACTION=update; fi
gcloud scheduler jobs "$COLLECTOR_SCHEDULE_ACTION" http "$JOB_NAME-daily" --location="$REGION" --project="$PROJECT_ID" \
    --schedule='0 7 * * *' --time-zone=Africa/Nairobi --http-method=POST \
    --uri="https://run.googleapis.com/v2/projects/$PROJECT_ID/locations/$REGION/jobs/$JOB_NAME:run" \
    --oauth-service-account-email="$TRIGGER_ACCOUNT" --message-body='{}' \
    --headers='Content-Type=application/json' --max-retry-attempts=0 --quiet
if [[ "${SCHEDULER_PAUSED:-false}" == true ]]; then
    gcloud scheduler jobs pause "$JOB_NAME-daily" --location="$REGION" --project="$PROJECT_ID" --quiet
    echo 'Collector deployed with its daily scheduler paused until application verification completes.'
else
    echo 'Collector deployed. Daily schedule: 07:00 Africa/Nairobi. No immediate collection was requested.'
fi
