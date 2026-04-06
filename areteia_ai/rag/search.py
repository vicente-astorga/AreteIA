import faiss
import pickle
import numpy as np
import os
from pathlib import Path

from rag.embed import embed_texts
from rag.store import get_index_path, get_metadata_path, save_index, load_index
from rag.utils import extract_pdf, extract_docx, extract_pptx, embed_text_chunks

from langchain.text_splitter import RecursiveCharacterTextSplitter

def search_index(course_id, query, k=5):
    index_path = get_index_path(course_id)
    metadata_path = get_metadata_path(course_id)

    # Load index
    index = faiss.read_index(index_path)

    # Load metadata
    with open(metadata_path, "rb") as f:
        metadata = pickle.load(f)

    # Embed query
    query_vector = embed_texts([query])
    query_vector = np.array(query_vector).astype("float32")

    # Search
    distances, indices = index.search(query_vector, k)

    results = []
    for i in indices[0]:
        if i < len(metadata):
            results.append(metadata[i])

    return results


def search_course(course_id: int, query: str, top_k=5):
    index, metadata = load_index(course_id)
    query_emb = embed_text_chunks([query])[0]
    D, I = index.search(query_emb.reshape(1, -1), top_k)
    results = []
    for idx in I[0]:
        if idx < len(metadata):
            results.append(metadata[idx])
    return results
