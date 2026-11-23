import { useCallback, useEffect, useRef, useState } from 'react'
import { generateBrainstormingProposal } from '@/api/aiBrainstorming'
import { AiGenerationError, isAiGenerationError } from './errors'
import type { AiBrainstormingRequest, AiBrainstormingResponse } from './types'

export type AiGenerationStatus =
  | 'idle'
  | 'generating'
  | 'success'
  | 'error'
  | 'cancelled'

export interface AiGenerationState {
  status: AiGenerationStatus
  response: AiBrainstormingResponse | null
  error: AiGenerationError | null
}

const initialState: AiGenerationState = {
  status: 'idle',
  response: null,
  error: null,
}

/**
 * Dedicated generation state machine. Kept out of the dialog so the form stays
 * a presentation concern and the request lifecycle (one in flight, cancel,
 * retry with the same request) can be reasoned about in one place.
 *
 * Retrying never mints new content: it re-sends the stored validated request
 * and, like the contract requires, creates nothing.
 */
export function useBrainstormingGeneration() {
  const [state, setState] = useState<AiGenerationState>(initialState)
  const controllerRef = useRef<AbortController | null>(null)
  const requestRef = useRef<AiBrainstormingRequest | null>(null)
  const runningRef = useRef(false)
  const mountedRef = useRef(true)

  useEffect(() => {
    mountedRef.current = true
    return () => {
      mountedRef.current = false
      controllerRef.current?.abort()
      controllerRef.current = null
      runningRef.current = false
    }
  }, [])

  const generate = useCallback(async (request: AiBrainstormingRequest) => {
    // A second click while a request is in flight must not spend another call.
    if (runningRef.current) return

    runningRef.current = true
    requestRef.current = request

    const controller = new AbortController()
    controllerRef.current = controller
    setState({ status: 'generating', response: null, error: null })

    try {
      const response = await generateBrainstormingProposal(request, {
        signal: controller.signal,
      })
      if (!mountedRef.current || controller.signal.aborted) return
      setState({ status: 'success', response, error: null })
    } catch (error) {
      if (!mountedRef.current) return
      const generationError = isAiGenerationError(error)
        ? error
        : new AiGenerationError('internal', 'The generation failed unexpectedly.', {
            cause: error,
          })

      if (generationError.kind === 'cancelled') {
        setState({ status: 'cancelled', response: null, error: null })
      } else {
        setState({ status: 'error', response: null, error: generationError })
      }
    } finally {
      if (controllerRef.current === controller) {
        controllerRef.current = null
        runningRef.current = false
      }
    }
  }, [])

  const cancel = useCallback(() => {
    controllerRef.current?.abort()
    controllerRef.current = null
    runningRef.current = false
    setState({ status: 'cancelled', response: null, error: null })
  }, [])

  const retry = useCallback(() => {
    const request = requestRef.current
    if (!request) return
    void generate(request)
  }, [generate])

  /** Back to the form; also aborts a request that is still running. */
  const reset = useCallback(() => {
    controllerRef.current?.abort()
    controllerRef.current = null
    runningRef.current = false
    setState(initialState)
  }, [])

  return { state, generate, cancel, retry, reset }
}
