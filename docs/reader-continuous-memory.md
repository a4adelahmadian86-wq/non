# Continuous PDF reader memory management

Continuous PDF mode creates lightweight page placeholders for the document, but rasterizes only pages close to the scroll viewport.

- The IntersectionObserver window uses a 650px vertical margin to pre-render nearby pages.
- When a page leaves that window, its canvas backing store is reset to zero dimensions and its text layer is cleared. The measured page box is retained as a placeholder so scroll position and page layout remain stable.
- If a page is still rendering when it leaves the window, release is deferred until the render completes. Re-entering pages remain resident; visible pages are rerendered after zoom/resize invalidation.
- Browsers without IntersectionObserver use a requestAnimationFrame-throttled scroll fallback that keeps only nearby pages rendered.
- Leaving continuous mode disconnects observers and fallback listeners and removes the generated page wrappers.

This bounds the number of high-resolution canvas surfaces retained during long-document reading. PDF.js may still retain internal parsed objects, and memory use varies by page size, zoom, and device pixel ratio.
