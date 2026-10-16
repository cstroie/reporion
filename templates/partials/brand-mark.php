<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The brand mark inline (assets/brand/mark.svg): the page takes the text colour
 * of its parent and the signature's ink is --brand-ink (default: the same), so
 * every palette recolours it. Variables in scope: string $markClass
 */

declare(strict_types=1);

/** @var string $markClass */
?>
<svg class="<?= htmlspecialchars($markClass ?? 'wk-brand-mark', ENT_QUOTES) ?>" viewBox="0 0 64 64" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><defs><path id="sgi" d="M12.4 50.4C14.8 45.4 18.1 34.8 21.4 32.3C24.7 29.9 23.8 38.9 19.7 45.4C17.3 49.5 21.4 50.4 23.8 44.6C25.5 40.5 27.1 40.5 27.1 44.6C27.1 47.9 29.6 47.1 31.2 43.0C32.9 39.7 34.5 40.5 34.5 43.8C34.5 47.1 37.8 46.3 39.4 43.0C41.9 38.9 46.8 38.1 53.4 40.5"/><path id="uli" d="M14.0 54.5C24.7 50.4 40.2 51.2 51.7 46.3"/><linearGradient id="fdi" gradientUnits="userSpaceOnUse" x1="12" y1="0" x2="62" y2="0"><stop offset="0" style="stop-color:var(--brand-ink, currentColor);stop-opacity:1"/><stop offset="1" style="stop-color:var(--brand-ink, currentColor);stop-opacity:1"/></linearGradient><mask id="mki" maskUnits="userSpaceOnUse" x="-10" y="-10" width="90" height="90"><rect x="-10" y="-10" width="90" height="90" fill="#fff"/><g stroke="#000" stroke-width="6.5"><use href="#sgi"/><use href="#uli" stroke-width="5"/></g></mask></defs><g mask="url(#mki)"><g transform="rotate(-5 32 32)" stroke="currentColor"><path d="M17 6h20l12 12v36a4 4 0 0 1-4 4H17a4 4 0 0 1-4-4V10a4 4 0 0 1 4-4z" stroke-width="3"/><path d="M37 6v8a4 4 0 0 0 4 4h8" stroke-width="3"/><g stroke-width="2.3" opacity=".6"><path d="M19 14h9" stroke-width="3"/><path d="M19 20c3-1.500 5 1 8 0s4-1.500 6 0s5 0 6-.5"/><path d="M19 25c3-1 5 1 8 0s5-1 7 0s6 1 9 0"/><path d="M19 30c3-1 5 1 8 0s4-1 6 0"/></g></g></g><g stroke="url(#fdi)" stroke-width="2.6"><use href="#sgi"/><use href="#uli" stroke-width="1.8"/></g></svg>
