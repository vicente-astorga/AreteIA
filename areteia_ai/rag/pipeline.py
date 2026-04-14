import logging, os
import numpy as np
from pathlib import Path
from rag.store import save_index
from rag.utils import extract_pdf, extract_docx, extract_pptx, embed_text_chunks
from langchain.text_splitter import RecursiveCharacterTextSplitter
BASE_PATH = os.getenv("ARETEIA_SYNC_PATH", "/var/www/moodledata/areteia_sync")


def run_ingestion(course_id: int,  chunk_size=1500, overlap=100, progress_callback=None):
    """
    Read course folder, split files into chunks, embed, save index and metadata.
    """
    def update_p(val, msg):
        logging.info(f"[Course {course_id}] {val}% - {msg}")
        if progress_callback: progress_callback(val, msg)

    all_chunks = []
    metadata = []
    
    update_p(5, "Iniciando escaneo de archivos...")
    folder_path = Path(BASE_PATH) / f"course_{course_id}"
    if not folder_path.exists():
        update_p(0, f"Error: Carpeta no encontrada")
        raise FileNotFoundError(f"Folder {folder_path} not found")

    try:
        all_files = list(folder_path.rglob("*"))
    except Exception as e:
        update_p(0, f"Error en rglob")
        return 0

    files_to_process = [f for f in all_files if f.is_file() and f.suffix.lower() in ['.pdf', '.docx', '.pptx']]
    total_files = len(files_to_process)
    
    for idx, file_path in enumerate(files_to_process):
        try:
            # Progress 10% to 50% for extraction
            p_val = 10 + int((idx / (total_files or 1)) * 40)
            update_p(p_val, f"Extrayendo texto: {file_path.name}")
            
            ext = file_path.suffix.lower()
            text = ""
            if ext == ".pdf":
                text = extract_pdf(file_path)
            elif ext == ".docx":
                text = extract_docx(file_path)
            elif ext == ".pptx":
                text = extract_pptx(file_path)
            else:
                continue
        except Exception as e:
            continue

        # Option B: Advanced splitting with larger context and paragraph awareness
        splitter = RecursiveCharacterTextSplitter(
            chunk_size=chunk_size, 
            chunk_overlap=overlap,
            separators=["\n\n", "\n", ".", " ", ""]
        )
        chunks = splitter.split_text(text)


        for i, chunk in enumerate(chunks):
            all_chunks.append(chunk)
            metadata.append({
                "filename": file_path.name,
                "path": str(file_path),
                "chunk_id": i,
                "text": chunk
            })

    # 3. Generate Embeddings (Manual Batching for progress updates)
    batch_size = 32
    total_chunks = len(all_chunks)
    all_embeddings = []
    
    if total_chunks > 0:
        update_p(50, f"Generando vectores para {total_chunks} fragmentos...")
        
        for start_idx in range(0, total_chunks, batch_size):
            end_idx = min(start_idx + batch_size, total_chunks)
            batch = all_chunks[start_idx:end_idx]
            
            # Embed this batch
            batch_embeddings = embed_text_chunks(batch, prefix="passage: ")
            all_embeddings.extend(batch_embeddings)
            
            # Progress 50% to 90%
            progress = 50 + int((end_idx / total_chunks) * 40)
            update_p(progress, f"Embeddings: {end_idx}/{total_chunks}")

        embeddings_np = np.array(all_embeddings).astype("float32")
        
        # 4. Save Index and Metadata
        update_p(90, "Guardando índice en disco...")
        save_index(course_id, embeddings_np, metadata)
        update_p(100, "¡Biblioteca construida con éxito!")
        return total_chunks
    else:
        update_p(0, "Error: No se encontró texto para procesar")
        return 0


def search_course(course_id: int, query: str, top_k=5):
    index, metadata = load_index(course_id)
    # query_emb is already normalized by embed_text_chunks in utils.py
    query_emb = embed_text_chunks([query], prefix="query: ")[0]
    D, I = index.search(query_emb.reshape(1, -1), top_k)
    results = []
    for idx in I[0]:
        if idx < len(metadata):
            results.append(metadata[idx])
    return results
