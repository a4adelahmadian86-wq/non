const { test, expect } = require('@playwright/test');
const path = require('node:path');

const readerAudioScript = path.join(__dirname, '../../public/js/farast-reader-audio.js');

const alignedTimings = [
  {
    text: 'سلام دنیا.',
    page: 1,
    start: 0,
    end: 1,
    alignment_quality: 'phoneme',
    words: [
      { text: 'سلام', start: 0.1, end: 0.4 },
      { text: 'دنیا.', start: 0.5, end: 0.9 },
    ],
  },
  {
    text: 'سلام دنیا.',
    page: 1,
    start: 1.1,
    end: 2.1,
    alignment_quality: 'phoneme',
    words: [
      { text: 'سلام', start: 1.2, end: 1.5 },
      { text: 'دنیا.', start: 1.6, end: 2 },
    ],
  },
  {
    text: 'عنوان بخش',
    page: 1,
    start: 2.2,
    end: 3,
    alignment_quality: 'phoneme',
    words: [
      { text: 'عنوان', start: 2.3, end: 2.6 },
      { text: 'بخش', start: 2.7, end: 2.9 },
    ],
  },
  {
    text: 'سلول',
    page: 1,
    start: 3.1,
    end: 3.7,
    alignment_quality: 'phoneme',
    words: [{ text: 'سلول', start: 3.2, end: 3.6 }],
  },
  {
    text: 'ردیف مقدار',
    page: 1,
    start: 3.8,
    end: 4.8,
    alignment_quality: 'phoneme',
    words: [
      { text: 'ردیف', start: 3.9, end: 4.2 },
      { text: 'مقدار', start: 4.3, end: 4.7 },
    ],
  },
];

async function mountAudioReader(page, timings = alignedTimings) {
  await page.goto('/');
  await page.setContent(`
    <section class="farast-reader" data-reader-root data-reader-mode="document" data-theme="dark">
      <article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" data-audio-url="/reader-audio-fixture.wav">
        <div class="reader-document-page">
          <p class="reader-document-paragraph" tabindex="0" data-reader-speech-text="سلام دنیا.">
            <span class="reader-document-sentence" tabindex="0" data-reader-speech-text="سلام دنیا.">
              <span class="reader-document-word" tabindex="0" role="button" data-word="سلام">سلام</span>
              <span> </span>
              <span class="reader-document-word" tabindex="0" role="button" data-word="دنیا.">دنیا.</span>
            </span>
          </p>
          <p class="reader-document-paragraph" tabindex="0" data-reader-speech-text="سلام دنیا.">
            <span class="reader-document-sentence" tabindex="0" data-reader-speech-text="سلام دنیا.">
              <span class="reader-document-word" tabindex="0" role="button" data-word="سلام">سلام</span>
              <span> </span>
              <span class="reader-document-word" tabindex="0" role="button" data-word="دنیا.">دنیا.</span>
            </span>
          </p>
          <h2 class="reader-document-heading" tabindex="0" data-reader-speech-text="عنوان بخش">عنوان بخش</h2>
          <table>
            <tbody>
              <tr class="reader-document-row" tabindex="0" data-reader-speech-text="ردیف مقدار">
                <td class="reader-document-cell" tabindex="0" data-reader-speech-text="سلول">سلول</td>
                <td class="reader-document-cell" tabindex="0" data-reader-speech-text="مقدار">مقدار</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </section>
  `);

  await page.evaluate((sourceTimings) => {
    const root = document.querySelector('[data-reader-root]');
    root.dataset.readerAudioTiming = JSON.stringify(sourceTimings);
    window.__readerAudioInstances = [];

    class FakeAudio extends EventTarget {
      constructor() {
        super();
        this.paused = true;
        this.currentTime = 0;
        this.duration = 10;
        this.readyState = 1;
        this.seeking = false;
        this.volume = 1;
        this.playbackRate = 1;
        this.preload = '';
        this._src = '';
        window.__readerAudioInstances.push(this);
      }

      set src(value) { this._src = String(value || ''); }
      get src() { return this._src; }
      get currentSrc() { return this._src; }
      setAttribute() {}
      removeAttribute(name) { if (name === 'src') this._src = ''; }
      load() { this.dispatchEvent(new Event('loadedmetadata')); }
      play() {
        this.paused = false;
        this.dispatchEvent(new Event('play'));
        return Promise.resolve();
      }
      pause() {
        if (!this.paused) {
          this.paused = true;
          this.dispatchEvent(new Event('pause'));
        }
      }
    }

    window.Audio = FakeAudio;
    window.requestAnimationFrame = () => 1;
    window.cancelAnimationFrame = () => {};
  }, timings);

  await page.addScriptTag({ path: readerAudioScript });
  await expect(page.locator('.farast-audio-dock')).toBeVisible();
  await expect.poll(() => page.evaluate(() => window.__readerAudioInstances.length)).toBe(1);
}

test('maps click, keyboard, selected text and semantic targets to their source word intervals', async ({ page }) => {
  await mountAudioReader(page);
  const audioTime = () => page.evaluate(() => window.__readerAudioInstances[0].currentTime);

  const words = page.locator('.reader-document-word');
  await words.nth(0).click();
  expect(await audioTime()).toBeCloseTo(0.1, 3);

  await words.nth(3).focus();
  await page.keyboard.press('Enter');
  expect(await audioTime()).toBeCloseTo(1.6, 3);

  await page.getByRole('button', { name: 'خواندن پاراگراف' }).nth(1).click();
  expect(await audioTime()).toBeCloseTo(1.2, 3);

  await page.getByRole('button', { name: 'خواندن عنوان' }).click();
  expect(await audioTime()).toBeCloseTo(2.3, 3);

  await page.getByRole('button', { name: 'خواندن سلول' }).first().click();
  expect(await audioTime()).toBeCloseTo(3.2, 3);

  await page.getByRole('button', { name: 'خواندن ردیف' }).click();
  expect(await audioTime()).toBeCloseTo(3.9, 3);

  const article = page.locator('.farast-reader-page-item');
  await expect(article).toHaveAttribute('tabindex', '0');
  await expect(article).toHaveAttribute('aria-label', 'خواندن صفحه ۱');
  await article.focus();
  await page.keyboard.press('Enter');
  expect(await audioTime()).toBe(0);

  await page.evaluate(() => {
    const words = document.querySelectorAll('.reader-document-word');
    const range = document.createRange();
    range.setStart(words[2].firstChild, 0);
    range.setEnd(words[3].firstChild, words[3].textContent.length);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    document.querySelector('[data-reader-root]').dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
  });
  expect(await audioTime()).toBeCloseTo(1.2, 3);
});

test('keeps estimated chunk timing visibly approximate and preserves accessible playback controls', async ({ page }) => {
  await mountAudioReader(page, [{
    text: 'سلام دنیا.',
    page: 1,
    start: 0,
    end: 2,
    alignment_quality: 'estimated',
    words: [],
  }]);

  const hint = page.locator('[data-audio-word-hint]');
  await expect(hint).toContainText('تقریبی');
  await expect(hint).not.toContainText('دقیق');
  await expect(page.locator('[data-audio-play]')).toHaveAttribute('aria-label', 'پخش');
  await expect(page.locator('[data-audio-stop]')).toHaveAttribute('aria-label', 'توقف و بازگشت به ابتدا');
  await expect(page.locator('[data-audio-seek]')).toHaveAttribute('aria-label', 'موقعیت پخش');
  await expect(page.locator('[data-audio-speed]')).toHaveAttribute('aria-label', 'سرعت پخش');

  const audio = () => page.evaluate(() => window.__readerAudioInstances[0]);
  await page.locator('[data-audio-play]').click();
  expect((await audio()).paused).toBe(false);
  await page.locator('[data-audio-speed]').selectOption('1.5');
  expect((await audio()).playbackRate).toBe(1.5);

  await page.locator('[data-audio-seek]').evaluate(element => {
    element.value = '500';
    element.dispatchEvent(new Event('input', { bubbles: true }));
  });
  expect((await audio()).currentTime).toBe(5);

  await page.locator('[data-audio-stop]').click();
  expect((await audio()).paused).toBe(true);
  expect((await audio()).currentTime).toBe(0);
});
