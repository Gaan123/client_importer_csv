#!/usr/bin/env python3
"""
Large CSV to JSON Chunker - Save as Multiple JSON Files

This script converts large CSV files into smaller JSON chunk files.
Each chunk is saved as storage/chunks/{signature}/{signature}_{chunk_number}.json
Sets status to 'chunks_ready' so PHP jobs can process each chunk sequentially.

Usage:
    python process_large_csv.py <import_id> <csv_file_path> <env_file_path> <file_signature>
"""

import sys
import json
import os
import pandas as pd
from datetime import datetime
import psycopg2
from dotenv import load_dotenv


def convert_and_chunk(import_id, csv_file_path, env_file_path, file_signature):
    """Convert CSV to multiple JSON chunk files"""

    print(f"[{import_id}] Starting CSV to JSON chunking...")
    print(f"[{import_id}] Input: {csv_file_path}")
    print(f"[{import_id}] Signature: {file_signature}")

    db_conn = None
    db_cursor = None

    try:
        # Load database credentials from .env
        load_dotenv(env_file_path)

        # Connect to database
        db_conn = psycopg2.connect(
            host=os.getenv('DB_HOST', '127.0.0.1'),
            port=os.getenv('DB_PORT', '5432'),
            database=os.getenv('DB_DATABASE'),
            user=os.getenv('DB_USERNAME'),
            password=os.getenv('DB_PASSWORD')
        )
        db_cursor = db_conn.cursor()
        print(f"[{import_id}] Connected to database")

        # Check if file exists
        if not os.path.exists(csv_file_path):
            raise FileNotFoundError(f"CSV file not found: {csv_file_path}")

        file_size = os.path.getsize(csv_file_path)
        print(f"[{import_id}] File size: {file_size / (1024*1024):.2f} MB")

        # Create chunks directory in storage/app/chunks
        base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        chunks_dir = os.path.join(base_dir, 'storage', 'app', 'chunks', file_signature)
        os.makedirs(chunks_dir, exist_ok=True)
        print(f"[{import_id}] Chunks directory: {chunks_dir}")

        # Convert CSV to JSON chunks
        total_rows = 0
        chunk_file_index = 0
        rows_per_chunk = 5000  # Each JSON file will have 5000 rows
        current_chunk_rows = []
        chunk_files_created = []

        # Read CSV in chunks
        for chunk_num, chunk in enumerate(pd.read_csv(
            csv_file_path,
            chunksize=10000,
            dtype=str,
            encoding='utf-8',
            on_bad_lines='skip'
        )):
            print(f"[{import_id}] Processing CSV chunk {chunk_num + 1}...")

            # Convert chunk to records
            records = chunk.to_dict('records')

            for record in records:
                # Clean up None/NaN values
                cleaned_record = {
                    'company': str(record.get('company', '')).strip() if pd.notna(record.get('company')) else '',
                    'email': str(record.get('email', '')).strip() if pd.notna(record.get('email')) else '',
                    'phone': str(record.get('phone', '')).strip() if pd.notna(record.get('phone')) else '',
                }
                current_chunk_rows.append(cleaned_record)
                total_rows += 1

                # Save chunk when it reaches the desired size
                if len(current_chunk_rows) >= rows_per_chunk:
                    chunk_filename = f"{file_signature}_{chunk_file_index}.json"
                    chunk_filepath = os.path.join(chunks_dir, chunk_filename)

                    with open(chunk_filepath, 'w', encoding='utf-8') as f:
                        json.dump(current_chunk_rows, f, ensure_ascii=False)

                    chunk_files_created.append(chunk_filename)
                    print(f"[{import_id}] Created chunk {chunk_file_index}: {chunk_filename} ({len(current_chunk_rows)} rows)")

                    current_chunk_rows = []
                    chunk_file_index += 1

            # Log progress
            if (chunk_num + 1) % 10 == 0:
                print(f"[{import_id}] Processed {total_rows} rows, created {chunk_file_index} chunks...")

        # Save remaining rows as the last chunk
        if current_chunk_rows:
            chunk_filename = f"{file_signature}_{chunk_file_index}.json"
            chunk_filepath = os.path.join(chunks_dir, chunk_filename)

            with open(chunk_filepath, 'w', encoding='utf-8') as f:
                json.dump(current_chunk_rows, f, ensure_ascii=False)

            chunk_files_created.append(chunk_filename)
            print(f"[{import_id}] Created chunk {chunk_file_index}: {chunk_filename} ({len(current_chunk_rows)} rows)")
            chunk_file_index += 1

        print(f"[{import_id}] Chunking complete! Total rows: {total_rows}, Total chunks: {chunk_file_index}")

        # Update database with chunk metadata
        print(f"[{import_id}] Updating database with chunk metadata...")

        import_data = {
            'chunks_directory': f"chunks/{file_signature}",
            'total_chunks': chunk_file_index,
            'total_rows': total_rows,
            'rows_per_chunk': rows_per_chunk,
            'chunk_files': chunk_files_created,
            'chunked_at': datetime.now().isoformat()
        }

        db_cursor.execute(
            """UPDATE imports
               SET status = %s,
                   total_rows = %s,
                   data = %s::jsonb,
                   updated_at = NOW()
               WHERE id = %s""",
            ('chunks_ready', total_rows, json.dumps(import_data), import_id)
        )
        db_conn.commit()

        print(f"[{import_id}] Metadata saved to database. Status set to 'chunks_ready'")
        print(f"[{import_id}] PHP jobs will process {chunk_file_index} chunks sequentially.")

        return 0

    except Exception as e:
        print(f"[{import_id}] ERROR: {str(e)}", file=sys.stderr)

        # Update status to failed
        if db_cursor and db_conn:
            try:
                db_cursor.execute(
                    """UPDATE imports
                       SET status = 'failed',
                           data = %s::jsonb,
                           updated_at = NOW()
                       WHERE id = %s""",
                    (json.dumps({'error': str(e)}), import_id)
                )
                db_conn.commit()
            except:
                pass

        return 1

    finally:
        if db_cursor:
            db_cursor.close()
        if db_conn:
            db_conn.close()


def main():
    if len(sys.argv) != 5:
        print("Usage: python process_large_csv.py <import_id> <csv_file_path> <env_file_path> <file_signature>", file=sys.stderr)
        return 1

    import_id = sys.argv[1]
    csv_file_path = sys.argv[2]
    env_file_path = sys.argv[3]
    file_signature = sys.argv[4]

    return convert_and_chunk(import_id, csv_file_path, env_file_path, file_signature)


if __name__ == '__main__':
    sys.exit(main())
