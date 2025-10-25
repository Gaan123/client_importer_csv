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


