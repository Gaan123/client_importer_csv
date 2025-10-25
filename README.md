# Client Importer

A Laravel-based application for importing, managing, and exporting client data with advanced duplicate detection and large-file processing capabilities.

## Project Overview

Client Importer is a full-stack web application built with Laravel 11, Vue 3, and PostgreSQL. It provides a robust solution for managing large-scale client data imports and exports, with sophisticated duplicate detection and memory-efficient processing.

## Tech Stack

- **Backend**: Laravel 11 (PHP)
- **Frontend**: Vue 3 + PrimeVue UI components
- **Database**: PostgreSQL with JSONB support
- **Queue System**: Laravel Queue for async processing
- **Authentication**: Laravel Sanctum

## Key Features

### 1. Client Management
- **CRUD Operations**: Full create, read, update, delete functionality for clients
- **Search**: Real-time search across company name, email, and phone (with 500ms debounce)
- **Duplicate Filtering**: Toggle to show only clients with duplicates
- **Pagination**: Efficient pagination for large datasets

### 2. CSV Import System
- **Smart File Handling**:
  - Small files (<10MB): Immediate validation and processing
  - Large files (>10MB): Async processing with queue jobs
- **Duplicate Detection**:
  - Company name matching
  - Email matching
  - Phone number matching
  - Tracks duplicate IDs in client metadata
- **Mass Insert Optimization**: Uses PostgreSQL bulk inserts for performance
- **Import Validation**: Pre-import validation with detailed error reporting
- **Import Logs**: Comprehensive logging with status tracking (queued, processing, completed, failed)

### 3. Export System
- **Intelligent Export**:
  - Small datasets (≤10,000 clients): Synchronous export
  - Large datasets (>10,000 clients): Async queue-based export
- **PostgreSQL Streaming**: Uses PostgreSQL `COPY` with streaming cursor for memory efficiency
  - Handles 900,000+ rows without memory issues
  - Exports directly from database to CSV
  - No row limits or timeouts
- **Export Management**:
  - List all export files with metadata
  - Download exports
  - Delete old exports
  - File size formatting (MB/GB)
- **Export Format**: CSV with columns - ID, Company, Email, Phone, Has Duplicates, Created At

### 4. Import Details View
- **Row-Level Details**: View all rows from an import with status indicators
- **Export Import Logs**: Export detailed import results to CSV
- **Status Tracking**: Success, failed, duplicate status per row
- **Error Messages**: Detailed error reporting for failed rows
- **Pagination**: Handle large imports efficiently

### 5. Duplicate Detection & Management
- **View Duplicates**: Dedicated page showing all duplicates for a specific client
- **Smart Detection**:
  - Checks company + email + phone combination
  - Individual field matching (company-only, email-only, phone-only)
  - Stores duplicate IDs in JSONB metadata
- **Background Processing**: Duplicate detection runs async after import/client creation

### 6. Performance Optimizations
- **Memory Efficient Processing**:
  - PostgreSQL streaming cursors
  - JSONB for flexible data storage
  - Mass insert operations (5000 rows per batch)
  - Chunked file reading
- **Python/Pandas CSV Chunker**:
  - Separate Python script (`scripts/process_large_csv.py`) for processing extremely large CSV files
  - Uses pandas to read CSV in chunks (10,000 rows at a time)
  - Converts CSV to smaller JSON chunk files (5,000 rows per file)
  - Stores chunks in `storage/app/chunks/{signature}/` directory
  - Enables Laravel to process massive files without memory constraints
  - Updates database status to `chunks_ready` for sequential processing
- **Queue Jobs**:
  - Import processing
  - Export generation
  - Duplicate detection
- **Database Optimization**:
  - Unique constraints on (company, email, phone)
  - Indexed fields for fast lookups
  - JSONB for metadata storage

## Installation

### Prerequisites
- PHP 8.2+
- PostgreSQL 14+
- Node.js 20+
- Composer
- Python 3.8+ with pandas, psycopg2, python-dotenv (for large CSV processing)

### Setup

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd client_importer
   ```

2. **Install dependencies**
   ```bash
   composer install
   npm install
   pip install pandas psycopg2-binary python-dotenv
   ```

3. **Environment configuration**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Configure database** (`.env`)
   ```
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=client_importer
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```

5. **Run migrations**
   ```bash
   php artisan migrate
   ```

6. **Seed the database** (creates admin user)
   ```bash
   php artisan db:seed
   ```

   Default admin credentials:
   - Email: `admin@admin.com`
   - Password: `password`

7. **Create storage directories**
   ```bash
   php artisan storage:link
   mkdir -p storage/app/private/exports
   chmod -R 775 storage
   ```

8. **Build frontend**
   ```bash
   npm run build
   # Or for development:
   npm run dev
   ```

9. **Start queue worker**
   ```bash
   php artisan queue:work --queue=default,imports,exports
   ```

10. **Start the server**
   ```bash
   php artisan serve
   ```

11. **Login to the application**
   - Navigate to `http://localhost:8000/login`
   - Use the default admin credentials from seeder (email: `admin@admin.com`, password: `password`)

## Testing & Development

### Generate Test CSV Files

The application includes a built-in command to generate test CSV files for development and testing:

```bash
php artisan generate:test-csv
```

**Generated Files** (saved to `storage/test_csvs/`):

1. **`clients_100_valid.csv`** - 100 valid client records for basic testing
2. **`clients_10_errors.csv`** - 10 rows with various validation errors:
   - 3 valid rows
   - 2 invalid email format/length errors
   - 3 missing required fields (company, email, phone)
   - 2 unique constraint violations (duplicates)
3. **`clients_large.csv`** - Large file (~108MB with 900,000 rows) for performance testing
   - Contains 90 duplicate rows scattered throughout (every 10,000th row)
   - Tests async import processing, chunking, and memory efficiency

**Options:**
- `--path=custom/path` - Specify custom output directory (default: `storage/test_csvs`)
- `--skip-large` - Skip generating the large 100MB file

**Example:**
```bash
# Generate all test files
php artisan generate:test-csv

# Generate only small test files (skip large file)
php artisan generate:test-csv --skip-large

# Generate to custom directory
php artisan generate:test-csv --path=public/samples
```

## Usage

### Importing Clients

1. Navigate to **Clients** page
2. Click **"Import CSV"**
3. Select your CSV file (must have: company, email, phone columns)
4. Wait for validation and processing
5. Check **"Import Logs"** for detailed results

### Exporting Clients

1. Navigate to **Clients** page
2. Click **"Export Clients"** (opens exports page)
3. Click **"New Export"** to create a new export
4. For large datasets (>10,000 rows): Export queues automatically, check back in a few minutes
5. Download or delete exports from the list

### Managing Duplicates

1. View clients with "Yes" in the **Duplicates** column
2. Click **"View"** to see all duplicate matches
3. Review duplicate IDs and decide on merge/delete actions

## API Endpoints

### Clients
- `GET /api/clients` - List clients (with search & filters)
- `POST /api/clients` - Create client
- `GET /api/clients/{id}` - Get client details
- `PUT /api/clients/{id}` - Update client
- `DELETE /api/clients/{id}` - Delete client
- `GET /api/clients/{id}/duplicates` - Get duplicates for a client

### Imports
- `POST /api/clients/import` - Import CSV
- `GET /api/imports` - List all imports
- `GET /api/imports/{id}` - Get import details
- `GET /api/imports/{id}/export` - Export import log
- `DELETE /api/imports/{id}` - Delete import

### Exports
- `GET /api/clients/export` - Create new export (sync or async)
- `GET /api/clients/exports` - List all export files
- `GET /api/clients/exports/{filename}/download` - Download export
- `DELETE /api/clients/exports/{filename}` - Delete export

## Architecture Highlights

### Data Storage
- **Clients**: PostgreSQL table with unique constraint on (company, email, phone)
- **Imports**: Stores import metadata + full JSONB data of all rows
- **Exports**: File-based storage in `storage/app/private/exports/`

### Job Queue
- `ProcessClientsImport`: Handles large CSV imports
- `GenerateClientsExport`: Creates exports asynchronously
- `DetectSingleClientDuplicate`: Finds duplicates for a client
- `GenerateImportExport`: Exports import logs

### Memory Management
- Uses PostgreSQL cursors for streaming large datasets
- Chunked processing (5000 rows per batch)
- No memory limits needed for exports (handled by PostgreSQL)

## Performance Benchmarks

- **Import**: 900,000 rows in ~4 minutes
- **Export**: 900,000 rows in ~2-3 minutes (90MB CSV)
- **Memory**: <400MB during large operations
- **Duplicate Detection**: Async, does not block user operations

## Architecture Decisions and Trade-offs

### 1. File Size Threshold Strategy

**Decision**: Split import/export processing into two strategies based on size thresholds
- **Imports**: 10MB file size threshold
- **Exports**: 10,000 row count threshold

**Rationale**:
- Small files/datasets can be processed synchronously for immediate feedback
- Large files/datasets are queued to prevent timeout and memory issues
- File size for imports (easier to check upfront without reading file)
- Row count for exports (already have this data in database)

**Trade-offs**:
- ✅ Better user experience for small operations (instant results)
- ✅ Prevents server timeout on large operations
- ❌ Adds complexity with two different code paths
- ❌ Users must wait and check back for large operations

### 2. PostgreSQL Streaming vs PHP Chunking

**Decision**: Use PostgreSQL streaming cursors (`PDO::CURSOR_FWDONLY`) for exports instead of Laravel's chunking

**Rationale**:
- PostgreSQL handles large datasets more efficiently than PHP
- Streaming directly from database to file avoids loading data into PHP memory
- Can process 900,000+ rows without memory limits
- No row limits or artificial constraints

**Trade-offs**:
- ✅ Minimal memory usage (<400MB for 900K rows)
- ✅ Faster export speed (~2-3 minutes for 90MB CSV)
- ✅ No need to set PHP memory limits
- ❌ Less portable (PostgreSQL-specific feature)
- ❌ Lower-level PDO code instead of Eloquent abstractions

### 3. Python Pandas for CSV Chunking

**Decision**: Use external Python script with pandas for processing extremely large CSV files

**Rationale**:
- Pandas is optimized for large CSV processing
- Can read files in chunks without loading entire file into memory
- Converts CSV to smaller JSON chunks for sequential processing
- Separates concerns: Python for file processing, Laravel for business logic

**Trade-offs**:
- ✅ Handles files of any size without memory constraints
- ✅ Leverages pandas' optimized CSV parsing
- ✅ Enables parallel/batch processing of chunks
- ❌ Requires Python runtime and dependencies
- ❌ Additional complexity with cross-language integration
- ❌ Extra step in deployment process

### 4. Hybrid Import Strategy: Python Chunking + PHP Queue Processing

**Decision**: Use Python pandas for CSV-to-JSON chunking, then PHP queue jobs for processing chunks, instead of pure PHP streaming import

**Rationale**:
- **Python pandas** excels at CSV parsing and can handle malformed files gracefully
- **Chunking approach** breaks massive files into manageable pieces (5,000 rows per chunk)
- **PHP queue jobs** process chunks sequentially with retry logic and error tracking
- Separates concerns: file parsing (Python) vs business logic (PHP/Laravel)
- Enables fine-grained progress tracking (chunk by chunk)

**Workflow**:
```
1. User uploads large CSV (>10MB)
2. File saved to storage/app/imports/
3. Python script invoked via Symfony Process
4. Python reads CSV in 10K row batches, outputs 5K row JSON chunks
5. Python updates DB: status = 'chunks_ready', stores chunk metadata
6. PHP queue picks up chunks sequentially
7. Each chunk: validate → mass insert → track results
8. Final status update with success/failure counts
```

**Trade-offs**:
- ✅ Handles any file size (tested with 900K rows)
- ✅ Pandas handles CSV edge cases (quotes, escapes, encodings)
- ✅ Resume-able (can restart from failed chunk)
- ✅ Detailed progress tracking per chunk
- ✅ Memory efficient (never loads full file)
- ❌ Requires Python runtime + dependencies (pandas, psycopg2)
- ❌ Cross-language complexity (PHP ↔ Python)
- ❌ Additional failure points (Python process, file I/O)
- ❌ Slower than pure streaming (multiple read/write cycles)

**Alternative Considered**: Pure PHP streaming with `fgetcsv()` + chunked processing
```php
// Not chosen
$handle = fopen($file, 'r');
while (($row = fgetcsv($handle)) !== false) {
    $batch[] = $row;
    if (count($batch) >= 5000) {
        processBatch($batch);
        $batch = [];
    }
}
```

**Why Hybrid Approach Chosen**:
- **CSV parsing reliability**: Pandas handles malformed CSV better than PHP `fgetcsv()`
- **Memory guarantees**: Pandas chunking is more predictable than PHP streaming
- **Separation of concerns**: File parsing isolated from business logic
- **Intermediate format**: JSON chunks are easier to debug than raw CSV
- **Parallel processing potential**: Could process multiple chunks concurrently (future enhancement)

**When Pure PHP Works**:
- Small files (<10MB): Direct Laravel collection processing
- Well-formed CSV: `fgetcsv()` is sufficient
- Simple validation: No complex business rules

**When Hybrid Required**:
- Large files (>10MB): Memory constraints
- Malformed CSV: Encoding issues, inconsistent quotes/delimiters
- Long-running imports: Need progress tracking and resume capability
- High reliability: Need chunk-level retry logic

### 5. JSONB for Metadata Storage

**Decision**: Use PostgreSQL JSONB columns for flexible metadata and import data storage

**Rationale**:
- Flexible schema for storing arbitrary data (duplicate IDs, chunk info, errors)
- Efficient querying with PostgreSQL JSONB operators
- Avoids creating multiple tables for varied metadata structures
- Native JSON merge operations for updates

**Trade-offs**:
- ✅ Schema flexibility for evolving requirements
- ✅ Efficient JSONB operations (merge without loading)
- ✅ Single table for imports instead of complex relations
- ❌ Less type safety compared to structured columns
- ❌ Harder to enforce constraints on nested data
- ❌ PostgreSQL-specific (not portable to MySQL)

### 6. File-Based Export Management

**Decision**: Store exports as files instead of database records with blob storage

**Rationale**:
- Large CSV files (90MB+) are expensive to store in database
- File system is optimized for large binary data
- Simpler cleanup and management (just delete files)
- Direct streaming to users without loading into memory

**Trade-offs**:
- ✅ Efficient storage for large files
- ✅ Simple download implementation (direct file streaming)
- ✅ Easy cleanup (delete files older than X days)
- ❌ No database-level export tracking/metadata
- ❌ File system becomes source of truth
- ❌ Requires disk space management strategy

### 7. Duplicate Storage Strategy: JSONB in Clients Table vs Separate Duplicates Table

**Decision**: Store duplicate IDs as JSONB in each client's `extras` column (within `clients` table) instead of creating a separate `client_duplicates` relationship table

**Current Approach** - All data in `clients` table:
```sql
-- clients table structure
CREATE TABLE clients (
    id SERIAL PRIMARY KEY,
    company VARCHAR(255),
    email VARCHAR(255),
    phone VARCHAR(22),
    has_duplicates BOOLEAN DEFAULT false,
    extras JSONB,  -- Stores duplicate_ids here
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Example client record with duplicates
{
  "id": 100,
  "company": "Acme Corp",
  "email": "contact@acme.com",
  "phone": "+1-555-0001",
  "has_duplicates": true,
  "extras": {
    "duplicate_ids": {
      "company": [123, 456],    -- Other clients with same company
      "email": [789],            -- Other clients with same email
      "phone": [234, 567, 890]   -- Other clients with same phone
    }
  }
}
```

**Rationale**:
- Duplicates are metadata about a client, not independent entities requiring a separate table
- Eliminates JOIN operations when listing/filtering clients
- Single database query retrieves client + all duplicate info
- JSONB allows flexible categorization by match type (company, email, phone)
- Simpler updates (modify one JSONB field vs managing pivot table relationships)
- Boolean `has_duplicates` flag enables fast filtering without parsing JSONB

**Trade-offs**:
- ✅ Fast queries (no JOINs needed to check duplicates)
- ✅ Single row contains all duplicate information
- ✅ Flexible schema (can add new match types easily)
- ✅ Atomic updates (update one field vs multiple rows in pivot table)
- ✅ Simple filtering with `has_duplicates` boolean index
- ❌ Can't efficiently query "show all clients that are duplicates of client X" (reverse lookup)
- ❌ Denormalized data (duplicate relationship stored in both A→B and B→A)
- ❌ No foreign key constraints (duplicate IDs could reference deleted clients)
- ❌ Manual referential integrity (when deleting client, must update all clients referencing it)

**Alternative Considered**: Store all records in `clients` table with `is_duplicate` flag and `original_client_id` reference

```sql
-- Not chosen: Hierarchical duplicate tracking in same table
CREATE TABLE clients (
    id SERIAL PRIMARY KEY,
    company VARCHAR(255),
    email VARCHAR(255),
    phone VARCHAR(22),
    is_duplicate BOOLEAN DEFAULT false,        -- Flag: is this a duplicate?
    original_client_id INT REFERENCES clients(id), -- FK to "master" record
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Example: Original client
INSERT INTO clients (id, company, email, phone, is_duplicate, original_client_id)
VALUES (100, 'Acme Corp', 'contact@acme.com', '+1-555-0001', false, NULL);

-- Example: Duplicate records point to original
INSERT INTO clients (id, company, email, phone, is_duplicate, original_client_id)
VALUES (123, 'Acme Corp', 'different@email.com', '+1-555-0001', true, 100);

INSERT INTO clients (id, company, email, phone, is_duplicate, original_client_id)
VALUES (456, 'Acme Corp', 'contact@acme.com', '+1-555-9999', true, 100);
```

**Advantages of This Approach**:
- ✅ **Foreign key integrity**: Database enforces that `original_client_id` references valid client
- ✅ **Simple reverse lookup**: `SELECT * FROM clients WHERE original_client_id = 100` gets all duplicates
- ✅ **Clear hierarchy**: One "master" record, others are subordinates
- ✅ **Easy cascade delete**: Can configure `ON DELETE CASCADE` to auto-delete all duplicates
- ✅ **Single table**: No additional tables needed

**Drawbacks (Why Rejected)**:
- ❌ **Data duplication**: Each duplicate is a full row with redundant data (company, email, phone stored multiple times)
- ❌ **Ambiguous "original"**: Who decides which record is the "master"? First import? Newest? User choice?
- ❌ **Lost data on merge**: If you merge duplicates into one, you lose the other records entirely
- ❌ **Inflated row count**: 1 client with 10 duplicates = 11 database rows (10x storage overhead)
- ❌ **Complex queries**: Counting unique clients requires `WHERE is_duplicate = false`, always need to filter
- ❌ **No match type categorization**: Can't distinguish "matched by email" vs "matched by phone"
- ❌ **Unique constraint conflict**: Can't have unique constraint on (company, email, phone) if duplicates exist as separate rows
- ❌ **Import complexity**: During bulk import, must decide which record is "original" before inserting
- ❌ **Visualization challenge**: UI shows duplicate rows as separate entries, confusing users
- ❌ **Update propagation**: If updating "master" company name, must update all duplicate rows too

**Why This Doesn't Fit The Use Case**:

This approach treats duplicates as **separate entities** (hierarchical master-slave relationship), but in this application:
- Duplicates are **not separate records** - they're the *same* client entered multiple times
- Goal is **deduplication**, not maintaining parallel versions
- Users need to **see which existing clients match** on company/email/phone, not create subordinate copies
- Import process should **reject true duplicates** (unique constraint) and **flag potential duplicates** (similar records)

**Current Approach (JSONB) Advantages**:
- Treats duplicates as **relationships between equal peers**, not master-slave hierarchy
- One client row can match multiple other clients on different criteria (company, email, phone)
- No data duplication (only store IDs, not full records)
- Preserves all client data independently
- Users can view any client and see "these other clients are similar"
- Fast filtering: `WHERE has_duplicates = true` without complex joins

### 8. Async Duplicate Detection

**Decision**: Run duplicate detection as background jobs after import/create/update

**Rationale**:
- Duplicate checking across large datasets is expensive
- Shouldn't block user operations (imports, client creation)
- Results aren't needed immediately
- Can be retried if it fails

**Trade-offs**:
- ✅ Non-blocking user operations
- ✅ Better user experience (no waiting)
- ✅ Resilient to failures (can retry)
- ❌ Eventual consistency (duplicates not immediately visible)
- ❌ Race conditions possible (concurrent updates)
- ❌ Users may not notice when duplicates are detected

### 9. Unique Constraint on (company, email, phone)

**Decision**: Database-level unique constraint on the combination of all three fields

**Rationale**:
- Prevents true duplicates at the database level
- Fast duplicate detection (uses index)
- Atomic validation (no race conditions)
- Business rule: same company can't have same email AND phone

**Trade-offs**:
- ✅ Guaranteed data integrity
- ✅ Fast lookups using composite index
- ✅ Prevents race conditions
- ❌ Strict constraint (may reject legitimate variations)
- ❌ All three fields required (can't have partial data)
- ❌ Harder to update records (must ensure new combo is unique)

### 10. Laravel Sanctum for API Authentication

**Decision**: Use Laravel Sanctum instead of OAuth or JWT

**Rationale**:
- Simple SPA authentication with cookies
- No token management complexity
- Built into Laravel (no external dependencies)
- Sufficient for single-domain application

**Trade-offs**:
- ✅ Simple setup and maintenance
- ✅ Secure cookie-based authentication
- ✅ No token refresh logic needed
- ❌ Limited to single domain (not ideal for mobile apps)
- ❌ Less flexible than OAuth for third-party integrations
- ❌ Cookie-based (requires CORS configuration for subdomains)

### 11. Queue-Based Architecture

**Decision**: Use Laravel Queue for all async operations (imports, exports, duplicate detection)

**Rationale**:
- Decouples long-running tasks from HTTP requests
- Prevents timeouts on large operations
- Enables retry logic and failure handling
- Can be scaled horizontally (multiple workers)

**Trade-offs**:
- ✅ Prevents request timeouts
- ✅ Enables background processing
- ✅ Retry and failure handling built-in
- ✅ Horizontally scalable
- ❌ Requires queue worker process
- ❌ Additional complexity (job classes, queue management)
- ❌ Users must poll for status updates

### 12. Mass Insert with Validation

**Decision**: Validate rows before import, then use mass insert for successful rows

**Rationale**:
- Fast bulk inserts (5000 rows per batch)
- Pre-validation prevents partial imports
- Clear error reporting for failed rows
- Leverages PostgreSQL's batch insert efficiency

**Trade-offs**:
- ✅ Very fast imports (900K rows in ~4 minutes)
- ✅ Clear validation errors before committing
- ✅ Minimal database round-trips
- ❌ Memory overhead for validation phase
- ❌ All-or-nothing per chunk (chunk fails if any row fails constraints)
- ❌ Two-phase process (validate, then insert)

### Summary

The architecture prioritizes **performance**, **scalability**, and **memory efficiency** over simplicity. These 12 key architectural decisions work together to create a system capable of handling massive datasets efficiently.

**Core Architectural Themes**:

- **Leverage database capabilities**: PostgreSQL streaming cursors, JSONB operations, composite indexes, and unique constraints for data integrity
- **Async by default**: Queue jobs for all potentially slow operations (imports, exports, duplicate detection)
- **Two-tier processing**: Small files/datasets = sync (immediate feedback), large files/datasets = async (reliability and no timeouts)
- **Hybrid language approach**: Python for optimized CSV parsing, Laravel for business logic and API
- **Denormalized storage patterns**: JSONB for flexible metadata, file-based storage for large exports
- **Progressive enhancement**: Simple PHP processing for small files, sophisticated Python chunking for large files


**Complexity Trade-offs**:
The system accepts higher architectural complexity (Python+PHP, queue jobs, JSONB denormalization) in exchange for:
- Ability to handle files of any size
- Consistent performance regardless of dataset size
- Graceful degradation (small files remain fast, large files don't break)
- Maintainable codebase with clear separation of concerns

These decisions enable the application to handle real-world production workloads with 900,000+ rows while maintaining good user experience and operational reliability.

