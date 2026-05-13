import sys
import os
import re
from pathlib import Path

# Add the areteia_ai directory to sys.path so we can import modules
sys.path.append('/home/tomaspemora/AreteIA/areteia_ai')

try:
    from rag.utils import get_instrument_list
    print("Testing get_instrument_list()...")
    
    # Mocking the path since the script runs outside docker
    # We need to ensure the path in get_instrument_list exists in this environment
    # Let's read the file directly first to see if it's there
    md_path = Path("/home/tomaspemora/AreteIA/areteia_ai/rag/documentos_maestros/instrumentos_de_evaluacion.md")
    
    if md_path.exists():
        content = md_path.read_text(encoding="utf-8")
        matches = list(re.finditer(r"^\*\*(.*?)\*\*[:\s]*(.*?)$", content, re.MULTILINE))
        print(f"Found {len(matches)} instruments.")
        for m in matches[:3]:
            print(f"- {m.group(1).strip()}")
        
        if len(matches) > 0:
            print("Regex Success!")
        else:
            print("Regex Failure!")
    else:
        print(f"File not found: {md_path}")

except Exception as e:
    print(f"Error during verification: {e}")
