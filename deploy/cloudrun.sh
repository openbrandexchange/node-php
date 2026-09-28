#!/usr/bin/env bash
# Deploys the portal to Google Cloud Run.
#
# Recommended production setup (what this script does):
#   - the container from ./Dockerfile, built by Cloud Build
#   - Cloud SQL for MySQL as the database (Cloud Run disks are temporary)
#   - a Cloud Storage bucket for images (S3-compatible access with an HMAC key)
#   - the secret key in Secret Manager
#   - Cloud Scheduler calling the portal's cron address every minute (background work)
#
# Usage:
#   PROJECT=my-project REGION=europe-west4 PUBLIC_URL=https://brands.example.com ./deploy/cloudrun.sh
# Optional:
#   SERVICE=obx-portal  SQL_INSTANCE=obx-sql  BUCKET=my-project-obx-blobs  SQL_TIER=db-f1-micro
#   TRY_OUT=1   single instance with SQLite in the container (data is lost on every restart;
#               only for trying it out)
#
# Requires: gcloud (logged in, billing enabled on the project), openssl.
set -euo pipefail

: "${PROJECT:?Set PROJECT to your Google Cloud project id}"
REGION=${REGION:-europe-west4}
SERVICE=${SERVICE:-obx-portal}
SQL_INSTANCE=${SQL_INSTANCE:-obx-sql}
SQL_TIER=${SQL_TIER:-db-f1-micro}
BUCKET=${BUCKET:-${PROJECT}-obx-blobs}
DB_NAME=obx
DB_USER=obx
HERE=$(cd "$(dirname "$0")/.." && pwd)

gc() { gcloud --project "$PROJECT" "$@"; }
say() { printf '\n==> %s\n' "$*"; }

say "Enabling the Google Cloud services the portal uses"
gc services enable run.googleapis.com cloudbuild.googleapis.com artifactregistry.googleapis.com \
  secretmanager.googleapis.com sqladmin.googleapis.com cloudscheduler.googleapis.com storage.googleapis.com

say "Secret key (created once, kept in Secret Manager)"
if ! gc secrets describe obx-secret-key >/dev/null 2>&1; then
  openssl rand -base64 48 | tr -d '\n' | gc secrets create obx-secret-key --data-file=- --replication-policy=automatic
fi
SECRET_KEY=$(gc secrets versions access latest --secret=obx-secret-key)

ENV_VARS="OBX_PUBLIC_URL=${PUBLIC_URL:-},OBX_WORKER=0"
FLAGS=(--region "$REGION" --source "$HERE" --allow-unauthenticated --port 8080 --memory 1Gi --cpu 1 --timeout 600
  --set-secrets "OBX_SECRET_KEY=obx-secret-key:latest")

if [ "${TRY_OUT:-0}" = "1" ]; then
  say "Try-out mode: SQLite inside the container, one instance, data is NOT kept"
  FLAGS+=(--max-instances 1 --set-env-vars "$ENV_VARS")
else
  say "Cloud SQL for MySQL ($SQL_INSTANCE)"
  if ! gc sql instances describe "$SQL_INSTANCE" >/dev/null 2>&1; then
    gc sql instances create "$SQL_INSTANCE" --database-version=MYSQL_8_0 --tier="$SQL_TIER" --region="$REGION"
  fi
  gc sql databases describe "$DB_NAME" --instance "$SQL_INSTANCE" >/dev/null 2>&1 ||
    gc sql databases create "$DB_NAME" --instance "$SQL_INSTANCE" --charset=utf8mb4 --collation=utf8mb4_bin
  if ! gc secrets describe obx-db-password >/dev/null 2>&1; then
    openssl rand -base64 24 | tr -d '\n/+=' | gc secrets create obx-db-password --data-file=- --replication-policy=automatic
    gc sql users create "$DB_USER" --instance "$SQL_INSTANCE" --password="$(gc secrets versions access latest --secret=obx-db-password)"
  fi
  CONNECTION=$(gc sql instances describe "$SQL_INSTANCE" --format='value(connectionName)')

  say "Cloud Storage bucket for images ($BUCKET) with an HMAC key"
  gc storage buckets describe "gs://$BUCKET" >/dev/null 2>&1 ||
    gc storage buckets create "gs://$BUCKET" --location="$REGION" --uniform-bucket-level-access
  if ! gc secrets describe obx-s3-secret >/dev/null 2>&1; then
    SA=$(gc projects describe "$PROJECT" --format='value(projectNumber)')-compute@developer.gserviceaccount.com
    gc storage buckets add-iam-policy-binding "gs://$BUCKET" --member="serviceAccount:$SA" --role=roles/storage.objectAdmin >/dev/null
    read -r ACCESS_ID HMAC_SECRET < <(gc storage hmac create "$SA" --format='value(metadata.accessId,secret)')
    printf '%s' "$ACCESS_ID" | gc secrets create obx-s3-key-id --data-file=- --replication-policy=automatic
    printf '%s' "$HMAC_SECRET" | gc secrets create obx-s3-secret --data-file=- --replication-policy=automatic
  fi

  ENV_VARS="$ENV_VARS,OBX_DB_DSN=mysql:unix_socket=/cloudsql/${CONNECTION};dbname=${DB_NAME};charset=utf8mb4,OBX_DB_USER=${DB_USER}"
  ENV_VARS="$ENV_VARS,OBX_BLOB_STORE=s3,OBX_S3_ENDPOINT=https://storage.googleapis.com,OBX_S3_REGION=auto,OBX_S3_BUCKET=${BUCKET},OBX_S3_PATH_STYLE=true"
  FLAGS+=(--add-cloudsql-instances "$CONNECTION" --set-env-vars "$ENV_VARS"
    --set-secrets "OBX_SECRET_KEY=obx-secret-key:latest,OBX_DB_PASSWORD=obx-db-password:latest,OBX_S3_ACCESS_KEY_ID=obx-s3-key-id:latest,OBX_S3_SECRET_ACCESS_KEY=obx-s3-secret:latest")
fi

say "Building and deploying $SERVICE"
gc run deploy "$SERVICE" "${FLAGS[@]}"
URL=$(gc run services describe "$SERVICE" --region "$REGION" --format='value(status.url)')
BASE=${PUBLIC_URL:-$URL}

say "Background work: Cloud Scheduler calls the cron address every minute"
# The cron key is derived from the secret key exactly like the portal does it.
MASTER=$(printf '%s' "$SECRET_KEY" | openssl dgst -sha256 -hex | awk '{print $NF}')
CRON_KEY=$(printf 'obx:cron' | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$MASTER" -binary | base64 | tr '+/' 'Az' | tr -d '=\n' | cut -c1-32)
if gc scheduler jobs describe "$SERVICE-cron" --location "$REGION" >/dev/null 2>&1; then
  gc scheduler jobs update http "$SERVICE-cron" --location "$REGION" --schedule '* * * * *' --uri "$BASE/cron?key=$CRON_KEY" --http-method GET
else
  gc scheduler jobs create http "$SERVICE-cron" --location "$REGION" --schedule '* * * * *' --uri "$BASE/cron?key=$CRON_KEY" --http-method GET
fi

cat <<EOF

Done. Your portal: $BASE
Open it and follow the setup wizard. The one-time setup code is in the logs:
  gcloud --project $PROJECT run services logs read $SERVICE --region $REGION | grep "Setup code"
Custom domain: gcloud --project $PROJECT beta run domain-mappings create --service $SERVICE --domain brands.example.com --region $REGION
then set PUBLIC_URL to that address and run this script again.
EOF
