import os
from pathlib import Path
from rag.store import save_index
from rag.utils import extract_pdf, extract_docx, extract_pptx, embed_text_chunks
from langchain.text_splitter import RecursiveCharacterTextSplitter
BASE_PATH = os.getenv("ARETEIA_SYNC_PATH", "/var/www/moodledata/areteia_sync")


def run_ingestion(course_id: int,  chunk_size=500, overlap=50):
    """
    Read course folder, split files into chunks, embed, save index and metadata.
    """
    all_chunks = []
    metadata = []

    folder_path = Path(BASE_PATH) / f"course_{course_id}"
    if not folder_path.exists():
        raise FileNotFoundError(f"Folder {folder_path} not found")

    for file_path in folder_path.rglob("*"):
        if not file_path.is_file():
            continue
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
        splitter = RecursiveCharacterTextSplitter(chunk_size=chunk_size, chunk_overlap=overlap)
        chunks = splitter.split_text(text)

        for i, chunk in enumerate(chunks):
            all_chunks.append(chunk)
            metadata.append({
                "filename": file_path.name,
                "path": str(file_path),
                "chunk_id": i,
                "text": chunk
            })

    if not all_chunks:
        return 0

    embeddings = embed_text_chunks(all_chunks)
    save_index(course_id, embeddings, metadata)
    return len(all_chunks)

