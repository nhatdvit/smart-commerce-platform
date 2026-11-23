import { ApiError } from './api-client';

/** The first field message from a 422, falling back to the envelope's own message. */
export function apiErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    const [firstFieldError] = Object.values(error.fieldErrors());

    return firstFieldError ?? error.message;
  }

  return 'Có lỗi xảy ra, vui lòng thử lại.';
}
