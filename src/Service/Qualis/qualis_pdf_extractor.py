import sys
import re
import json
import pypdf

def extract_qualis(pdf_path, output_json_path):
    print(f"Reading PDF: {pdf_path}")
    reader = pypdf.PdfReader(pdf_path)
    total_pages = len(reader.pages)
    print(f"Total pages to process: {total_pages}")
    
    # Regex: ISSN (XXXX-XXXX), Title (everything in between), Qualis (A1-A4, B1-B4, C)
    regex = re.compile(r'^(\d{4}-\d{3}[\dXx])\s+(.+)\s+([A-D][1-4]?|C)$')
    
    journals = []
    skipped_lines = 0
    
    for i, page in enumerate(reader.pages):
        if (i + 1) % 50 == 0 or (i + 1) == total_pages:
            print(f"Processing page {i + 1}/{total_pages}...")
            
        text = page.extract_text()
        if not text:
            continue
            
        for line in text.split("\n"):
            line = line.strip()
            # Ignore headers and footers
            if not line or "Qualis CAPES" in line or "ISSN TíTULO ESTRATO" in line or line.startswith("Página "):
                continue
                
            m = regex.match(line)
            if m:
                issn = m.group(1).strip()
                title = m.group(2).strip()
                qualis = m.group(3).strip()
                
                # Normalize ISSN for search indexes (remove hyphen, lowercase)
                normalized_issn = issn.replace("-", "").strip().lower()
                
                journals.append({
                    "issn": issn,
                    "normalized_issn": normalized_issn,
                    "title": title,
                    "qualis": qualis
                })
            else:
                skipped_lines += 1
                
    print(f"Extraction completed. Mapped journals: {len(journals)}. Skipped lines/garbage: {skipped_lines}")
    
    with open(output_json_path, 'w', encoding='utf-8') as f:
        json.dump(journals, f, ensure_ascii=False, indent=2)
        
    print(f"Saved extracted JSON to: {output_json_path}")

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python3 qualis_pdf_extractor.py <input_pdf_path> <output_json_path>")
        sys.exit(1)
        
    pdf_in = sys.argv[1]
    json_out = sys.argv[2]
    extract_qualis(pdf_in, json_out)
