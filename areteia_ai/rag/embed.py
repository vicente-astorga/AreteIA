from sentence_transformers import SentenceTransformer

# model = SentenceTransformer("all-MiniLM-L6-v2")
model = SentenceTransformer("sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2") # funciona mejor en español

def embed_texts(texts):
    return model.encode(texts, show_progress_bar=True)
