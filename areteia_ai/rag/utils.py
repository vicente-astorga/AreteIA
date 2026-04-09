from PyPDF2 import PdfReader
import docx
from pptx import Presentation
from sentence_transformers import SentenceTransformer
import numpy as np

# Lazy load model
_MODEL = None

def get_model():
    global _MODEL
    if _MODEL is None:
        from sentence_transformers import SentenceTransformer
        _MODEL = SentenceTransformer("sentence-transformers/all-MiniLM-L6-v2")
    return _MODEL

def extract_pdf(path):
    text = ""
    try:
        reader = PdfReader(str(path))
        for page in reader.pages:
            text += page.extract_text() + "\n"
    except Exception:
        pass
    return text

def extract_docx(path):
    text = ""
    try:
        doc = docx.Document(str(path))
        for para in doc.paragraphs:
            text += para.text + "\n"
    except Exception:
        pass
    return text

def extract_pptx(path):
    text = ""
    try:
        prs = Presentation(str(path))
        for slide in prs.slides:
            for shape in slide.shapes:
                if hasattr(shape, "text"):
                    text += shape.text + "\n"
    except Exception:
        pass
    return text

def embed_text_chunks(chunks):
    return np.array(get_model().encode(chunks, convert_to_numpy=True))
