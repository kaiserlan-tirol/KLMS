# SSL Certificate Issues in Production

## Problem
When running the payment processing script via cronjob on the production server, you get SSL certificate errors like:
```
Connection to IDM failed (Error setting certificate verify locations:
  CAfile: /etc/pki/tls/certs/ca-bundle.crt
  CApath: none for "https://idm.kaiserlan.at/api/users?limit=10&page=1".)
```

## Root Cause
The cronjob environment on Plesk doesn't have the same SSL certificate configuration as the interactive shell. The system is trying to verify SSL certificates but can't find the CA bundle.

## Solution
We've implemented a configurable SSL verification system that can be disabled in production environments where certificate verification is problematic.

## Setup Instructions for Production Server

### 1. Create Production Environment File
Copy the example file and configure it for your production environment:
```bash
cp .env.prod.local.example .env.prod.local
```

Edit `.env.prod.local` with your production values:
```bash
# Disable SSL verification for IDM if having certificate issues
KLMS_IDM_SSL_VERIFY=false

# Set your production IDM URL (should be https://idm.kaiserlan.at)
KLMS_IDM_URL=https://idm.kaiserlan.at
KLMS_IDM_AUTH=your_production_auth_credentials
KLMS_IDM_APIKEY=your_production_api_key

# Other production settings...
```

### 2. Test the Configuration
Test manually first to ensure it works:
```bash
export APP_ENV=prod
export KLMS_IDM_SSL_VERIFY=false
/.phpenv/versions/8.3/bin/php bin/console app:process-payments --match-only --limit=1 --env=prod
```

### 3. Update Cronjob
The automated script (`auto-process-payments.sh`) now:
- Sets `APP_ENV=prod` and `KLMS_IDM_SSL_VERIFY=false` automatically
- Uses `--env=prod` flag on all console commands
- Uses the correct PHP path for Plesk (`.phpenv/versions/8.3/bin/php`)

### 4. Alternative Solutions
If you prefer to fix the SSL certificates instead of disabling verification:

#### Option A: Update CA Bundle Path
Find the correct CA bundle location on your server:
```bash
php -r "print_r(openssl_get_cert_locations());"
```

Then update the HTTP client configuration in `config/packages/framework.yaml`:
```yaml
http_client:
    scoped_clients:
        idm_client:
            # ... existing config ...
            cafile: '/path/to/correct/ca-bundle.crt'
```

#### Option B: Use System Certificates
Some servers work better with:
```yaml
http_client:
    scoped_clients:
        idm_client:
            # ... existing config ...
            cafile: null  # Use system default
            verify_peer: true
            verify_host: true
```

### 5. Monitoring
Check the logs after deployment:
```bash
tail -f log/auto-payment-processing.log
```

The script will now log the environment variables being used, so you can verify the configuration is correct.
