import os
import logging
import dashscope
from dashscope import Generation
from dotenv import load_dotenv

load_dotenv()

dashscope.api_key = os.getenv("DASHSCOPE_API_KEY")
DEFAULT_MODEL = os.getenv("DASHSCOPE_MODEL", "qwen-plus")

# Fix for workspace-specific endpoints
base_url = os.getenv("DASHSCOPE_BASE_URL")
if base_url:
    dashscope.base_http_api_url = base_url

def generate_completion(prompt: str, system_prompt: str = "Eres un experto en pedagogía y diseño de instrumentos de evaluación."):
    """
    Calls Alibaba DashScope API (Qwen) for text generation.
    """
    try:
        messages = [
            {'role': 'system', 'content': system_prompt},
            {'role': 'user', 'content': prompt}
        ]
        response = Generation.call(
            model=DEFAULT_MODEL,
            messages=messages,
            result_format='message',
        )
        if response.status_code == 200:
            return response.output.choices[0].message.content
        else:
            logging.error(f"DashScope Error: {response.code} - {response.message}")
            return None
    except Exception as e:
        logging.exception("Exception during DashScope call")
        return None

def get_suggestions_prompt(course_summary, objective, dimensions, rag_context):
    return f"""
Como experto en evaluación educativa, propón 3 instrumentos de evaluación para el siguiente contexto:

Contexto del Curso: {course_summary}
Objetivo de Evaluación: {objective}
Dimensiones: {dimensions}

Fragmentos relevantes de materiales del curso (RAG):
{rag_context}

Formato de respuesta: JSON (lista de objetos con 'name', 'why', 'lim')
Ejemplo: [{{"name": "...", "why": "...", "lim": "..."}}]
Responde SOLO con el JSON.
"""

def get_design_prompt(chosen_instrument, objective, rag_context):
    return f"""
Diseña las consignas o ítems para un instrumento de evaluación del tipo: {chosen_instrument}.

Objetivo: {objective}
Materiales de referencia (RAG):
{rag_context}

El diseño debe ser profesional, justificado pedagógicamente y listo para ser presentado al docente.
Incluye instrucciones claras para el estudiante.
"""

def get_rubric_prompt(instrument_content, objective):
    return f"""
Genera una rúbrica analítica para el siguiente instrumento:
{instrument_content}

Objetivo evaluado: {objective}

Formato sugerido: Tabla con Criterios, Niveles y Puntajes.
"""
