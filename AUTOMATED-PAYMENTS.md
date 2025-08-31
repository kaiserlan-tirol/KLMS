# Automated Payment Processing Setup

## Overview
This setup automatically fetches PayPal emails and processes payments every 4 hours using a cronjob.

## What it does
1. **Fetches PayPal emails** from the last 2 days
2. **Matches payments** to users automatically (high confidence matches)
3. **Processes matched payments** (tickets first, then catering)
4. **Logs everything** to `var/log/auto-payment-processing.log`

## Files Created
- `bin/auto-process-payments.sh` - Main automation script
- `crontab-example.txt` - Crontab configuration examples
- `var/log/auto-payment-processing.log` - Processing logs

## Installation

### 1. Make sure the script is executable (already done)
```bash
chmod +x bin/auto-process-payments.sh
```

### 2. Test the script manually
```bash
./bin/auto-process-payments.sh
```

### 3. Install the cronjob
```bash
# Edit your crontab
crontab -e

# Add this line (adjust path if needed):
0 */4 * * * /Users/markuse/projects/privat/privat/kaiserlan.at/webseite/kaiserlan.at/bin/auto-process-payments.sh
```

### 4. Verify cronjob is installed
```bash
crontab -l
```

## Schedule Options

**Current (every 4 hours):**
```
0 */4 * * * /path/to/auto-process-payments.sh
```

**Every 2 hours:**
```
0 */2 * * * /path/to/auto-process-payments.sh
```

**Twice daily (8 AM and 8 PM):**
```
0 8,20 * * * /path/to/auto-process-payments.sh
```

**Business hours only (8 AM to 8 PM):**
```
0 8-20 * * * /path/to/auto-process-payments.sh
```

## Monitoring

### Check logs
```bash
tail -f var/log/auto-payment-processing.log
```

### View recent processing
```bash
tail -50 var/log/auto-payment-processing.log | grep "==="
```

### Check if cron is running (macOS)
```bash
sudo launchctl list | grep cron
```

### Check if cron is running (Linux)
```bash
sudo service cron status
```

## Manual Commands

You can still run the commands manually:

```bash
# Fetch emails from last 2 days
php bin/console app:fetch-payment-emails $(date -d '2 days ago' '+%Y-%m-%d') --source=paypal

# Match payments
php bin/console app:process-payments --match-only

# Process payments
php bin/console app:process-payments --process-only
```

## Troubleshooting

### Cronjob not running
1. Check if cron service is running
2. Check crontab is installed: `crontab -l`
3. Check system logs: `/var/log/cron` (Linux) or Console.app (macOS)

### Email connection issues
1. Check `.env` file has correct EMAIL_IMAP_* settings
2. Test connection: `php bin/console app:fetch-payment-emails $(date '+%Y-%m-%d') --test-connection`

### Permission issues
1. Make sure script is executable: `chmod +x bin/auto-process-payments.sh`
2. Check log directory permissions: `chmod 755 var/log`

## What happens automatically

1. **High confidence matches** (exact name matches, nicknames) → Automatically processed
2. **Medium confidence matches** → Flagged for manual review
3. **Low/no confidence** → Left unmatched for manual handling
4. **Duplicate payments** → Automatically skipped
5. **Processing errors** → Logged for review

The system is designed to be safe - it only auto-processes payments it's confident about!
