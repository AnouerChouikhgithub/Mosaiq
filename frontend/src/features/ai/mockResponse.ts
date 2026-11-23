import { AiGenerationError } from './errors'
import type {
  AiBrainstormingRequest,
  AiBrainstormingResponse,
  AiDetailLevel,
  AiLanguage,
  AiNoteType,
  AiSectionCategory,
} from './types'

/**
 * MOCK — Sprint 1B only.
 *
 * Sprint 1A owns `POST /api/ai/brainstorming/generate` and is developed in
 * parallel. This module returns a response with the exact contract shape so the
 * frontend can be built and tested without the backend. It contains no provider
 * call, is never bundled into a real generation path once
 * `VITE_AI_BRAINSTORMING_MOCK=false`, and the preview labels it as simulated.
 *
 * Delete this file (and the branch in `api/aiBrainstorming.ts`) when Sprint 2
 * connects the real endpoint.
 */

interface MockNoteTemplate {
  type: AiNoteType
  content: (topic: string) => string
}

interface MockSectionTemplate {
  category: AiSectionCategory
  title: string
  notes: MockNoteTemplate[]
}

const sectionsByLanguage: Record<AiLanguage, MockSectionTemplate[]> = {
  fr: [
    {
      category: 'goals',
      title: 'Objectifs de la session',
      notes: [
        { type: 'idea', content: (topic) => `Clarifier ${topic} en une phrase partagée` },
        { type: 'question', content: () => 'Quel résultat concret doit sortir de l’atelier ?' },
        { type: 'challenge', content: () => 'Éviter de mélanger exploration et décisions' },
        { type: 'opportunity', content: () => 'Une matinée suffit pour un premier cadrage' },
      ],
    },
    {
      category: 'audience',
      title: 'Publics concernés',
      notes: [
        { type: 'idea', content: (topic) => `L’équipe produit qui portera ${topic}` },
        { type: 'question', content: () => 'Qui décide et qui est seulement consulté ?' },
        { type: 'challenge', content: () => 'Les utilisateurs terrain sont peu disponibles' },
        { type: 'opportunity', content: () => 'Un groupe de cinq à sept personnes reste efficace' },
      ],
    },
    {
      category: 'ideas',
      title: 'Pistes à explorer',
      notes: [
        { type: 'idea', content: () => 'Commencer par un pilote sur un seul quartier' },
        { type: 'question', content: (topic) => `Quelle version minimale teste ${topic} ?` },
        { type: 'opportunity', content: () => 'Réutiliser les acquis de l’atelier précédent' },
        { type: 'challenge', content: () => 'Le budget ne couvre pas tout de suite l’ensemble' },
      ],
    },
    {
      category: 'risks',
      title: 'Risques et freins',
      notes: [
        { type: 'challenge', content: () => 'Périmètre flou et responsabilités non attribuées' },
        { type: 'question', content: () => 'Quel risque bloquerait le lancement ?' },
        { type: 'idea', content: () => 'Un point de suivi hebdomadaire limite la dérive' },
      ],
    },
    {
      category: 'actions',
      title: 'Prochaines étapes',
      notes: [
        { type: 'action', content: (topic) => `Nommer un responsable pour ${topic}` },
        { type: 'action', content: () => 'Planifier une revue dans deux semaines' },
        { type: 'question', content: () => 'Quand partager les premiers résultats ?' },
        { type: 'idea', content: () => 'Préparer une fiche d’une page pour l’équipe' },
      ],
    },
  ],
  en: [
    {
      category: 'goals',
      title: 'Session goals',
      notes: [
        { type: 'idea', content: (topic) => `State ${topic} in one shared sentence` },
        { type: 'question', content: () => 'Which concrete outcome must the session produce?' },
        { type: 'challenge', content: () => 'Keep exploration separate from decisions' },
        { type: 'opportunity', content: () => 'One morning is enough for a first frame' },
      ],
    },
    {
      category: 'audience',
      title: 'Who is involved',
      notes: [
        { type: 'idea', content: (topic) => `The product team that will own ${topic}` },
        { type: 'question', content: () => 'Who decides, and who is only consulted?' },
        { type: 'challenge', content: () => 'Field users have very little availability' },
        { type: 'opportunity', content: () => 'Five to seven people keep a session effective' },
      ],
    },
    {
      category: 'ideas',
      title: 'Options to explore',
      notes: [
        { type: 'idea', content: () => 'Start with a pilot in a single neighbourhood' },
        { type: 'question', content: (topic) => `What is the smallest version that tests ${topic}?` },
        { type: 'opportunity', content: () => 'Reuse what worked in the previous workshop' },
        { type: 'challenge', content: () => 'The budget does not cover everything at once' },
      ],
    },
    {
      category: 'risks',
      title: 'Risks and blockers',
      notes: [
        { type: 'challenge', content: () => 'Scope is fuzzy and owners are unnamed' },
        { type: 'question', content: () => 'Which risk would stop the launch?' },
        { type: 'idea', content: () => 'A weekly follow-up limits drift' },
      ],
    },
    {
      category: 'actions',
      title: 'Next steps',
      notes: [
        { type: 'action', content: (topic) => `Name an owner for ${topic}` },
        { type: 'action', content: () => 'Book a review in two weeks' },
        { type: 'question', content: () => 'When do we share the first results?' },
        { type: 'idea', content: () => 'Prepare a one-page brief for the team' },
      ],
    },
  ],
  ar: [
    {
      category: 'goals',
      title: 'أهداف الجلسة',
      notes: [
        { type: 'idea', content: (topic) => `توضيح ${topic} في جملة مشتركة` },
        { type: 'question', content: () => 'ما النتيجة الملموسة التي يجب أن تخرج من الورشة؟' },
        { type: 'challenge', content: () => 'تجنّب خلط الاستكشاف بالقرارات' },
        { type: 'opportunity', content: () => 'جلسة صباحية تكفي لإطار أولي' },
      ],
    },
    {
      category: 'audience',
      title: 'الجهات المعنية',
      notes: [
        { type: 'idea', content: (topic) => `فريق المنتج الذي سيتابع ${topic}` },
        { type: 'question', content: () => 'من يقرّر ومن يُستشار فقط؟' },
        { type: 'challenge', content: () => 'قلّة توفّر الفرق الميدانية' },
        { type: 'opportunity', content: () => 'مجموعة من خمسة إلى سبعة مشاركين فعّالة' },
      ],
    },
    {
      category: 'ideas',
      title: 'مسارات للاستكشاف',
      notes: [
        { type: 'idea', content: () => 'البدء بتجربة في حيّ واحد' },
        { type: 'question', content: (topic) => `ما النسخة الدنيا التي تختبر ${topic}؟` },
        { type: 'opportunity', content: () => 'الاستفادة من نتائج الورشة السابقة' },
        { type: 'challenge', content: () => 'الميزانية لا تغطي كل شيء فورًا' },
      ],
    },
    {
      category: 'risks',
      title: 'المخاطر والعوائق',
      notes: [
        { type: 'challenge', content: () => 'نطاق غير واضح ومسؤوليات غير موزّعة' },
        { type: 'question', content: () => 'أي خطر قد يمنع الانطلاق؟' },
        { type: 'idea', content: () => 'متابعة أسبوعية تحدّ من الانحراف' },
      ],
    },
    {
      category: 'actions',
      title: 'الخطوات التالية',
      notes: [
        { type: 'action', content: (topic) => `تسمية مسؤول عن ${topic}` },
        { type: 'action', content: () => 'تحديد مراجعة بعد أسبوعين' },
        { type: 'question', content: () => 'متى تُشارك النتائج الأولى؟' },
        { type: 'idea', content: () => 'تحضير ورقة من صفحة واحدة للفريق' },
      ],
    },
  ],
}

const sectionsForLevel: Record<AiDetailLevel, number> = {
  concise: 3,
  balanced: 4,
  detailed: 5,
}

const notesForLevel: Record<AiDetailLevel, number> = {
  concise: 2,
  balanced: 3,
  detailed: 4,
}

function clamp(value: string, max: number): string {
  const characters = Array.from(value)
  return characters.length <= max ? value : characters.slice(0, max).join('')
}

/** Builds the mocked `200` body from the request, for a deterministic demo. */
export function buildMockProposal(
  request: AiBrainstormingRequest,
): AiBrainstormingResponse {
  const sectionCount = sectionsForLevel[request.detailLevel]
  const noteCount = notesForLevel[request.detailLevel]
  const sections = sectionsByLanguage[request.language].slice(0, sectionCount)

  return {
    schemaVersion: '1.0',
    language: request.language,
    detailLevel: request.detailLevel,
    truncated: false,
    proposal: {
      title: clamp(request.topic, 120),
      objective: clamp(request.objective, 300),
      sections: sections.map((section, sectionIndex) => ({
        id: `s${sectionIndex + 1}`,
        title: clamp(section.title, 80),
        category: section.category,
        notes: section.notes.slice(0, noteCount).map((note, noteIndex) => ({
          id: `s${sectionIndex + 1}-n${noteIndex + 1}`,
          type: note.type,
          content: clamp(note.content(request.topic), 280),
        })),
      })),
    },
  }
}

/** ~800 ms keeps the loading state visible in the demo without feeling stuck. */
const MOCK_LATENCY_MS = 800

/** Resolves the mocked proposal, honouring cancellation like the real client. */
export function generateMockProposal(
  request: AiBrainstormingRequest,
  options: { signal?: AbortSignal } = {},
): Promise<AiBrainstormingResponse> {
  return new Promise((resolve, reject) => {
    if (options.signal?.aborted) {
      reject(new AiGenerationError('cancelled', 'Generation cancelled'))
      return
    }

    const timer = setTimeout(() => {
      options.signal?.removeEventListener('abort', onAbort)
      resolve(buildMockProposal(request))
    }, MOCK_LATENCY_MS)

    function onAbort() {
      clearTimeout(timer)
      reject(new AiGenerationError('cancelled', 'Generation cancelled'))
    }

    options.signal?.addEventListener('abort', onAbort, { once: true })
  })
}
