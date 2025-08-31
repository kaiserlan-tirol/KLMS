# Payment Email Processing System

This system allows you to automatically fetch and process payment notification emails from various sources (PayPal) and create incoming payment records in the KaiserLAN system.

## Features

- **Email Fetching**: Automatically fetch emails from IMAP servers using configurable connection settings
- **PayPal Support**: Parse PayPal payment notification emails and extract payment information
- **Duplicate Prevention**: Avoid creating duplicate payments by checking external IDs
- **Flexible Configuration**: Support for different email sources and processing limits
- **Dry Run Mode**: Test the system without actually creating payments
- **Connection Testing**: Verify email server connectivity

## Prerequisites

### Required PHP Extensions

1. **IMAP Extension**: Required for email fetching
   ```bash
   # On Ubuntu/Debian
   sudo apt-get install php-imap
   
   # On CentOS/RHEL
   sudo yum install php-imap
   
   # On macOS with Homebrew
   brew install imap-uw
   pecl install imap
   ```

2. **Restart your web server** after installing the extension

### Email Configuration

1. **Configure environment variables** in your `.env` file:
   ```env
   # Email fetcher configuration for payment processing
   IMAP_SERVER=imap.gmail.com
   IMAP_USERNAME=your-email@gmail.com
   IMAP_PASSWORD=your-app-password
   IMAP_PORT=993
   IMAP_SSL=true
   ```

2. **Email Account Setup**:
   - For Gmail: Use App Passwords instead of your regular password
   - For other providers: Ensure IMAP is enabled and use appropriate server settings

## Usage

### Basic Commands

1. **Test email connection**:
   ```bash
   php bin/console app:fetch-payment-emails 2024-01-01 --test-connection
   ```

2. **Fetch all payment emails since a specific date**:
   ```bash
   php bin/console app:fetch-payment-emails 2024-01-01
   ```

3. **Dry run mode** (preview what would be imported):
   ```bash
   php bin/console app:fetch-payment-emails 2024-01-01 --dry-run
   ```

4. **Fetch only PayPal emails**:
   ```bash
   php bin/console app:fetch-payment-emails 2024-01-01 --source=paypal
   ```

5. **Limit number of emails processed**:
   ```bash
   php bin/console app:fetch-payment-emails 2024-01-01 --limit=50
   ```

### Command Options

- `since`: Date since when to fetch emails (YYYY-MM-DD format)
- `--source`: Payment source to fetch (`paypal`, `all`) [default: `all`]
- `--dry-run`: Show what would be imported without actually importing
- `--limit`: Maximum number of emails to process [default: 100]
- `--test-connection`: Test email server connection and exit

## Supported Email Sources

### PayPal

The system automatically detects PayPal payment notification emails by:
- **Sender verification**: Emails from `paypal.com` or `paypal.de` domains
- **Content analysis**: Looking for payment-related keywords in subject and content
- **Data extraction**: Parsing amount, transaction ID, payer information, and references

**Extracted PayPal data**:
- Transaction ID
- Payment amount and currency
- Payer email and name
- Payment date
- Reference/note from payer

## Configuration

### Email Server Settings

Different email providers require different settings:

**Gmail**:
```env
IMAP_SERVER=imap.gmail.com
IMAP_PORT=993
IMAP_SSL=true
```

**Outlook/Hotmail**:
```env
IMAP_SERVER=outlook.office365.com
IMAP_PORT=993
IMAP_SSL=true
```

**Custom IMAP Server**:
```env
IMAP_SERVER=mail.yourdomain.com
IMAP_PORT=993
IMAP_SSL=true
```

## Troubleshooting

### Common Issues

1. **"IMAP extension is not installed"**
   - Install the PHP IMAP extension (see Prerequisites)
   - Restart your web server

2. **"Failed to connect to IMAP server"**
   - Check email server settings in `.env`
   - Verify username/password (use App Passwords for Gmail)
   - Ensure firewall allows IMAP connections

3. **"No emails found"**
   - Check date range (emails might be older than specified date)
   - Verify email account contains the expected emails
   - Check if emails are in the correct folder (INBOX)

4. **"Could not parse email"**
   - Email format might not match expected patterns
   - Check email content and update parsing patterns if needed

### Debug Mode

Enable verbose output for debugging:
```bash
php bin/console app:fetch-payment-emails 2024-01-01 --dry-run -vvv
```

## Security Considerations

1. **Email Credentials**:
   - Use App Passwords instead of regular passwords
   - Store credentials securely in environment variables
   - Never commit credentials to version control

2. **Email Access**:
   - Use read-only email accounts when possible
   - Limit IMAP access to specific applications
   - Consider using dedicated email addresses for payment notifications

3. **Data Processing**:
   - Validate all extracted data before creating payments
   - Log all processing activities
   - Implement rate limiting to prevent abuse

## Monitoring and Maintenance

### Regular Tasks

1. **Check processing logs**:
   ```bash
   tail -f var/log/prod.log | grep -i payment
   ```

2. **Monitor duplicate prevention**:
   - Check for repeated transaction IDs
   - Verify payment amounts match expectations

3. **Review unprocessed emails**:
   - Check for emails that couldn't be parsed
   - Update parsing patterns as needed

### Performance Considerations

- Set appropriate limits for batch processing
- Consider running during off-peak hours
- Monitor memory usage for large email volumes
- Implement email archiving strategy

## Integration with KaiserLAN

The processed emails automatically create `IncomingPayment` records that integrate with:

1. **Payment Matching System**: Automatically matches payments to orders/users
2. **Admin Interface**: View and manage all incoming payments
3. **User Profiles**: Associate payments with user accounts
4. **Reporting**: Track payment statistics and trends

## Future Enhancements

Potential improvements for the system:

1. **Additional Payment Sources**: Support for other payment providers
2. **Advanced Filtering**: More sophisticated email filtering options
3. **Webhook Integration**: Real-time payment processing via webhooks
4. **Machine Learning**: Improved payment matching using ML algorithms
5. **Email Archiving**: Automatic archiving of processed emails
