# Payment System Analysis and Consolidation Proposal

## Current Structure

### Tables/Entities:
1. **incoming_payment** - Raw incoming payments from various sources
2. **catering_credit_transaction** - Individual credit transactions 
3. **user_catering_credit** - Current user credit balances

### Issues Identified:

1. **Redundant Data**: IncomingPayment and CateringCreditTransaction overlap significantly
2. **Source Limitations**: IncomingPayment only handles external sources, not internal transactions
3. **Missing History**: No unified transaction log for all payment activities
4. **Complex Matching**: Duplicate logic across multiple services
5. **Limited Transaction Types**: Only catering-specific transactions tracked

## Proposed Consolidated Structure

### Single Unified Transaction System

```sql
-- Replace all three tables with one comprehensive transaction table
CREATE TABLE user_transaction (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_uuid CHAR(36) NOT NULL,
    
    -- Financial data
    amount INT NOT NULL COMMENT 'Amount in cents',
    currency VARCHAR(3) DEFAULT 'EUR',
    
    -- Transaction metadata
    type ENUM(
        'payment_received',     -- External payment received
        'order_payment',        -- Payment for order
        'order_refund',         -- Refund from order
        'credit_adjustment',    -- Manual credit adjustment
        'transfer_out',         -- Transfer to another user
        'transfer_in'           -- Transfer from another user
    ) NOT NULL,
    
    -- Source tracking
    source ENUM(
        'paypal',
        'revolut', 
        'n26',
        'sparkasse',
        'manual_admin',
        'system'
    ) NOT NULL,
    
    -- External references
    external_id VARCHAR(255) NULL COMMENT 'PayPal transaction ID, bank reference, etc',
    reference TEXT NULL COMMENT 'Payment reference/note',
    
    -- Order references (for order-related transactions)
    catering_order_id INT NULL,
    shop_order_id INT NULL,
    
    -- Status and processing
    status ENUM('pending', 'matched', 'processed', 'ignored', 'failed') DEFAULT 'pending',
    matched_confidence ENUM('high', 'medium', 'low', 'manual') NULL,
    processing_notes TEXT NULL,
    
    -- Duplicate detection
    is_duplicate BOOLEAN DEFAULT FALSE,
    duplicate_of_transaction_id INT NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    
    -- Indexes
    INDEX idx_user_type (user_uuid, type),
    INDEX idx_external_id (external_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    
    FOREIGN KEY (duplicate_of_transaction_id) REFERENCES user_transaction(id)
);

-- User balances (derived from transactions)
CREATE TABLE user_balance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_uuid CHAR(36) NOT NULL UNIQUE,
    catering_balance INT DEFAULT 0 COMMENT 'Current catering credit in cents',
    shop_balance INT DEFAULT 0 COMMENT 'Current shop credit in cents', 
    total_balance INT DEFAULT 0 COMMENT 'Total available balance in cents',
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_user (user_uuid)
);
```

## Migration Strategy

### Phase 1: Create New System
1. Create new `user_transaction` and `user_balance` tables
2. Migrate existing data from all three current tables
3. Create new unified services

### Phase 2: Update Services
1. Create `TransactionService` to replace multiple payment services
2. Update all payment processing to use unified system
3. Add duplicate detection logic

### Phase 3: Remove Old System
1. Update all references to use new system
2. Drop old tables
3. Clean up obsolete services

## Benefits

1. **Single Source of Truth**: All financial transactions in one place
2. **Comprehensive History**: Complete audit trail for all money movements
3. **Simplified Logic**: One service handles all transaction types
4. **Better Duplicate Detection**: Unified duplicate checking across all sources
5. **Flexible Sources**: Easy to add new payment sources (bank APIs, crypto, etc.)
6. **Performance**: Fewer joins, better indexing
7. **Consistency**: Same transaction handling for all payment types

## Implementation Details

### New TransactionService Methods:
```php
class TransactionService {
    public function recordPaymentReceived(User $user, int $amount, string $source, ?string $externalId = null): Transaction
    public function recordOrderPayment(User $user, Order $order, int $amount): Transaction  
    public function recordRefund(User $user, Order $order, int $amount): Transaction
    public function recordCreditAdjustment(User $user, int $amount, string $reason): Transaction
    public function checkForDuplicatePayment(int $amount, User $user, string $source, \DateTime $since): ?Transaction
    public function calculateUserBalance(User $user): array
    public function getTransactionHistory(User $user, ?string $type = null): array
}
```

### Duplicate Detection Logic:
- Check for transactions with same amount, user, and timeframe
- Mark duplicates automatically with reference to original
- Support manual override for legitimate duplicates

Would you like me to proceed with implementing this consolidated system?
