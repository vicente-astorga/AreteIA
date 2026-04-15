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

def classify_feedback(feedback_text: str) -> str:
    """
    Classifies if user feedback is a valid pedagogical adjustment request.
    Returns a JSON string matching FeedbackClassification schema.
    """
    system_prompt = """Eres un evaluador de entradas de usuario para una IA pedagógica.
Tu tarea es determinar si el texto del usuario es una solicitud válida de AJUSTE o CORRECCIÓN sobre un material de evaluación (ej: 'hazlo más difícil', 'usa otro caso', 'cambia el tono').
Si el usuario pide algo fuera de contexto (chistes, insultos, temas no educativos), marca is_valid como false.
Responde UNICAMENTE con un JSON: {"is_valid": bool, "reason": "breve explicación si es falso"}"""
    
    return generate_completion(feedback_text, system_prompt)

def get_suggestions_prompt(course_summary, objective, dimensions, full_context, feedback=""):
    feedback_sect = f"\n### AJUSTES REQUERIDOS POR EL DOCENTE (Prioridad alta):\n{feedback}\n" if feedback else ""
    return f"""
    Tu tarea es proponer 3 instrumentos de evaluación que estén perfectamente alineados con los objetivos y el contexto del curso.

### 1. CONTEXTO GENERAL DEL CURSO:
{course_summary}

### 2. OBJETIVOS DE APRENDIZAJE (Taxonomía de Bloom):
{objective}

### 3. DIMENSIONES PEDAGÓGICAS DEFINIDAS:
{dimensions}

### 4. MATERIALES DEL CURSO, DIRECTRICES Y CATÁLOGO DE INSTRUMENTOS:
{full_context}
{feedback_sect}

### INSTRUCCIONES CRÍTICAS:
1. Debes elegir exactamente 3 instrumentos de la "LISTA DE INSTRUMENTOS DISPONIBLES" proporcionada arriba. El valor de "name" en tu respuesta debe ser el NOMBRE EXACTO del catálogo.
2. Basándote en el contexto y las directrices, justifica detalladamente por qué cada uno de estos 3 instrumentos es la mejor opción.
3. Cada propuesta debe estar justificada pedagógicamente, mencionando cómo se alinea con el nivel de Bloom y qué directriz institucional cumple.
4. Responde UNICAMENTE en formato JSON:
{{
  "suggestions": [
    {{
      "name": "Nombre exacto del catálogo",
      "why": "Justificación detallada citando el contexto y la directriz aplicada.",
      "lim": "Limitación técnica del instrumento."
    }}
  ]
}}"""

def get_design_prompt(chosen_instrument, objective, full_context, feedback=""):
    feedback_sect = f"\n### AJUSTES ESPECÍFICOS SOLICITADOS (Prioridad alta):\n{feedback}\n" if feedback else ""
    return f"""Eres un especialista en evaluación educativa. Debes redactar el contenido técnico para un instrumento de tipo: {chosen_instrument}.

### OBJETIVOS A EVALUAR:
{objective}

### CONTEXTO, MATERIALES Y REGLAS DE REDACCIÓN:
{full_context}
{feedback_sect}

### REQUISITOS DE CALIDAD:
1. Los ítems deben redactarse siguiendo fielmente las DIRECTRICES PEDAGÓGICAS (estilo, claridad, neutralidad).
2. Debes incluir consignas que cubran los diferentes niveles de Bloom solicitados.
3. Cada ítem debe tener asociado su nivel de Bloom y un puntaje estimado.
4. Incluye instrucciones claras para el estudiante, basadas en el contexto del curso.

### FORMATO DE RESPUESTA (JSON ÚNICAMENTE):
{{
  "title": "Título descriptivo",
  "instructions": "Guía para el estudiante",
  "items": [
    {{
      "text": "Contenido del ítem/pregunta",
      "bloom_level": "NIVEL",
      "points": 10
    }}
  ],
  "justification": "Explica qué directrices específicas se aplicaron para garantizar la validez del instrumento."
}}"""

def get_rubric_prompt(instrument_content, objective, full_context, feedback=""):
    feedback_sect = f"\n### AJUSTES EN LA RÚBRICA:\n{feedback}\n" if feedback else ""
    return f"""Como experto en evaluación, genera una RÚBRICA ANALÍTICA para el siguiente instrumento.

### INSTRUMENTO A EVALUAR:
{instrument_content}

### OBJETIVOS DE APRENDIZAJE:
{objective}

### MARCO PEDAGÓGICO Y REGLAS DE RÚBRICAS:
{full_context}
{feedback_sect}

### REQUISITOS:
1. Define criterios claros y discriminativos basados en los materiales del curso.
2. Los descriptores de niveles deben seguir las reglas de redacción de las DIRECTRICES PEDAGÓGICAS.
3. Asegura una progresión lógica en los puntajes.

### FORMATO DE RESPUESTA (JSON ÚNICAMENTE):
{{
  "title": "Rúbrica de Evaluación",
  "criteria": [
    {{
      "name": "Nombre del criterio",
      "description": "Qué se evalúa",
      "levels": [
        {{
          "label": "Nivel (ej: Destacado)",
          "score": 10,
          "description": "Descriptor de desempeño"
        }}
      ]
    }}
  ]
}}"""
