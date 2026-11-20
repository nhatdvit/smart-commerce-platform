/**
 * Turning a photo a shop owner picked into something worth storing.
 *
 * A phone photo is three to eight megabytes of 4000px JPEG carrying the GPS
 * coordinates of wherever it was taken. The menu card shows it at about 120px.
 * So the browser downscales and re-encodes before anything is sent, which:
 *
 *   - keeps the upload inside the 2 MB the gateway and PHP both allow, so a
 *     normal phone photo does not simply fail;
 *   - drops the EXIF block, because a canvas re-encode carries no metadata
 *     across. A shop's product photo should not publish where it was taken;
 *   - costs the server no image processing, which is just as well: the API
 *     image ships neither GD nor Imagick.
 *
 * The server still validates independently - type sniffed from the bytes, size
 * capped - because anything done here is done on the client's side of the wire
 * and can be skipped by anyone who wants to.
 */

import { api } from './api-client';

/** Long edge of the stored image. A menu card shows ~120px; a detail view more. */
const MAX_EDGE = 1024;

/** Encoder quality. 0.82 is where WebP stops looking different and keeps shrinking. */
const QUALITY = 0.82;

/** What the file picker should offer, and all the server will accept. */
export const ACCEPTED_IMAGE_TYPES = 'image/jpeg,image/png,image/webp';

export interface UploadedImage {
  readonly image_url: string;
  readonly content_type: string;
  readonly byte_size: number;
}

/** Reads a file into an image the canvas can draw. */
async function decode(file: File): Promise<HTMLImageElement> {
  const url = URL.createObjectURL(file);

  try {
    return await new Promise<HTMLImageElement>((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = () => reject(new Error('Không đọc được tệp ảnh.'));
      image.src = url;
    });
  } finally {
    // Whether it loaded or not: an object URL holds the whole file in memory
    // until it is revoked.
    URL.revokeObjectURL(url);
  }
}

function targetSize(width: number, height: number): { width: number; height: number } {
  const longest = Math.max(width, height);

  if (longest <= MAX_EDGE) {
    return { width, height };
  }

  const scale = MAX_EDGE / longest;

  return { width: Math.round(width * scale), height: Math.round(height * scale) };
}

/**
 * Downscales and re-encodes, preferring WebP and falling back to JPEG.
 *
 * `toBlob` hands back a PNG when it does not know the type asked for, so the
 * result's own type decides what was produced rather than what was requested.
 */
async function shrink(file: File): Promise<Blob> {
  const image = await decode(file);
  const size = targetSize(image.naturalWidth, image.naturalHeight);

  const canvas = document.createElement('canvas');
  canvas.width = size.width;
  canvas.height = size.height;

  const context = canvas.getContext('2d');
  if (context === null) {
    throw new Error('Trình duyệt không xử lý được ảnh này.');
  }

  context.drawImage(image, 0, 0, size.width, size.height);

  const blob = await new Promise<Blob | null>((resolve) =>
    canvas.toBlob(resolve, 'image/webp', QUALITY),
  );

  if (blob !== null && blob.type === 'image/webp') {
    return blob;
  }

  const jpeg = await new Promise<Blob | null>((resolve) =>
    canvas.toBlob(resolve, 'image/jpeg', QUALITY),
  );

  if (jpeg === null) {
    throw new Error('Không xử lý được ảnh này.');
  }

  return jpeg;
}

/** Shrinks a chosen file and uploads it, returning where it was stored. */
export async function uploadProductImage(file: File): Promise<UploadedImage> {
  const blob = await shrink(file);
  const body = new FormData();

  // The name is for the multipart part only: the server generates its own and
  // never trusts this one.
  body.append('file', blob, 'product-image');

  return api.upload<UploadedImage>('/admin/products/images', body);
}
