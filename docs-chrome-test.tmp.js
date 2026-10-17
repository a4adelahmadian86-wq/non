const { JSDOM } = require('jsdom');
const fs = require('fs');
const hubSrc = fs.readFileSync('public/js/editor-voice-hub.js','utf8');
const chromeSrc = fs.readFileSync('public/js/editor-docs-chrome.js','utf8');

function makeDom({ sr, authed }) {
  const html = `<!doctype html><html><head><meta name="csrf-token" content="t"></head><body>
  <div id="farastWord" class="farast-editor-app" dir="rtl" data-authenticated="${authed?'1':'0'}">
    <main id="app-main"><header class="farast-appbar"></header>
      <div id="pagesViewport"></div>
      <footer class="farast-statusbar"><button id="mic" type="button"><span>تایپ صوتی</span></button></footer>
    </main>
  </div></body></html>`;
  const dom = new JSDOM(html, { url: 'http://127.0.0.1:8001/editor', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  if (sr) { w.SpeechRecognition = function(){ this.start=()=>{}; this.stop=()=>{}; this.abort=()=>{}; }; }
  // legacy runtime stub that opens old panel on #mic click
  w.eval(`(function(){var m=document.getElementById('mic');var p=document.createElement('div');p.id='farastVoicePanel';p.className='farast-voice-panel';document.body.appendChild(p);window.FarastVoiceRuntime={isOpen:function(){return p.classList.contains('is-open')},close:function(){p.classList.remove('is-open')}};m.addEventListener('click',function(){p.classList.add('is-open')});})();`);
  w.eval(hubSrc);
  w.eval(chromeSrc);
  return dom;
}
let pass=0, fail=0;
const ok=(name,cond)=>{ cond?pass++:(fail++,console.log('FAIL:',name)); };

// Scenario A: Chrome-like (SR present)
{
  const dom = makeDom({ sr:true, authed:false });
  const d = dom.window.document;
  ok('A1 docs mode active', d.getElementById('farastWord').classList.contains('farast-docs-mode'));
  ok('A2 toolbar built', !!d.querySelector('.fd-toolbar'));
  ok('A3 mic toolbar button visible with label', (()=>{const b=d.querySelector('[data-tool=voice]');return b && b.textContent.includes('تایپ صوتی');})());
  ok('A4 bottom mic pill exists', !!d.getElementById('fdMicOpen'));
  // click legacy #mic -> should open NEW panel, not legacy
  d.getElementById('mic').dispatchEvent(new dom.window.MouseEvent('click',{bubbles:true,cancelable:true}));
  ok('A5 new voice panel opened via #mic', d.getElementById('fdVoiceBox').classList.contains('show'));
  ok('A6 legacy panel suppressed', !d.getElementById('farastVoicePanel').classList.contains('is-open'));
  // engine pick: native available
  ok('A7 hasNative true', dom.window.FarastVoiceHub.hasNative()===true);
}
// Scenario B: Firefox-like (no SR), logged in → cloud preferred
{
  const dom = makeDom({ sr:false, authed:true });
  const d = dom.window.document;
  d.getElementById('fdMicOpen').dispatchEvent(new dom.window.MouseEvent('click',{bubbles:true,cancelable:true}));
  ok('B1 panel open', d.getElementById('fdVoiceBox').classList.contains('show'));
  ok('B2 message mentions cloud', d.getElementById('fdVMsg').textContent.includes('موتور ابری'));
  ok('B3 engines list has cloud first-class', JSON.stringify(dom.window.FarastVoiceHub.engines()).includes('"cloud"'));
  ok('B4 no classic redirect', !d.getElementById('farastWord').classList.contains('farast-docs-mode')===false);
}
// Scenario C: stale classic flag must be cleared and chrome still builds
{
  const dom = makeDom({ sr:true, authed:false });
  const w = dom.window;
  w.localStorage.setItem('farast.editor.chrome','classic');
  // reload scripts in a fresh dom simulating next visit without keep-classic
  const dom2 = makeDom({ sr:true, authed:false });
  dom2.window.localStorage.setItem('farast.editor.chrome','classic');
  dom2.window.eval(hubSrc); dom2.window.eval(chromeSrc); // second load skipped by guard; emulate fresh page instead:
  const dom3 = new JSDOM('<!doctype html><html><body><div id="farastWord" data-authenticated="0"><div id="pagesViewport"></div><footer class="farast-statusbar"><button id="mic"></button></footer></div></body></html>',{url:'http://127.0.0.1:8001/editor',runScripts:'outside-only'});
  dom3.window.localStorage.setItem('farast.editor.chrome','classic');
  dom3.window.eval(hubSrc); dom3.window.eval(chromeSrc);
  ok('C1 stale classic flag removed', dom3.window.localStorage.getItem('farast.editor.chrome')===null);
  ok('C2 docs chrome still built', !!dom3.window.document.querySelector('.fd-toolbar'));
}
console.log(`PASS ${pass} / FAIL ${fail}`);
process.exit(fail?1:0);
