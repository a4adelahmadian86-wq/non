import {createServer} from 'node:http';
import {Readable} from 'node:stream';

const HOST=process.env.HOST||'0.0.0.0';
const PORT=Number(process.env.PORT||7008);
const BASE=String(process.env.OLLAMA_BASE_URL||'http://ollama:11434').replace(/\/$/,'');
const MODEL=String(process.env.OLLAMA_MODEL||'qwen3:1.7b');
const TOKEN=String(process.env.FARAST_AI_GATEWAY_TOKEN||'');
const MAX_BODY=1024*1024;
if(!TOKEN)throw new Error('FARAST_AI_GATEWAY_TOKEN is required');
const json=(res,status,payload)=>{const body=Buffer.from(JSON.stringify(payload));res.writeHead(status,{'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store','Content-Length':body.length});res.end(body);};
function authorized(req){const header=String(req.headers.authorization||'');const supplied=header.startsWith('Bearer ')?header.slice(7).trim():'';if(!supplied||supplied.length!==TOKEN.length)return false;let diff=0;for(let i=0;i<TOKEN.length;i++)diff|=TOKEN.charCodeAt(i)^supplied.charCodeAt(i);return diff===0;}
async function readBody(req){const chunks=[];let size=0;for await(const chunk of req){size+=chunk.length;if(size>MAX_BODY)throw new Error('request_too_large');chunks.push(chunk);}return Buffer.concat(chunks).toString('utf8');}
async function ready(){const response=await fetch(BASE+'/api/tags',{signal:AbortSignal.timeout(2500)});if(!response.ok)return false;const data=await response.json();return Array.isArray(data.models)&&data.models.some(item=>String(item.name||'').startsWith(MODEL));}
const server=createServer(async(req,res)=>{
  if(req.method==='GET'&&req.url==='/health'){json(res,200,{ok:true,gateway:'local-ai'});return;}
  if(req.method==='GET'&&req.url==='/ready'){try{const ok=await ready();json(res,ok?200:503,{ok,model:MODEL,provider:'ollama'});}catch{json(res,503,{ok:false,model:MODEL,provider:'ollama'});}return;}
  if(req.method!=='POST'||req.url!=='/v1/chat/completions'){json(res,404,{error:{message:'not_found'}});return;}
  if(!authorized(req)){json(res,401,{error:{message:'invalid_gateway_token'}});return;}
  let payload;
  try{payload=JSON.parse(await readBody(req));}catch(error){json(res,error.message==='request_too_large'?413:400,{error:{message:error.message==='request_too_large'?'request_too_large':'invalid_json'}});return;}
  if(!Array.isArray(payload.messages)||payload.messages.length===0){json(res,422,{error:{message:'messages_required'}});return;}
  try{
    const upstream=await fetch(BASE+'/v1/chat/completions',{
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':req.headers.accept||'application/json'},
      body:JSON.stringify({...payload,model:MODEL,reasoning_effort:'none'}),
      signal:AbortSignal.timeout(120000)
    });
    const headers={'Content-Type':upstream.headers.get('content-type')||'application/json','Cache-Control':'no-cache, no-store','X-Accel-Buffering':'no','X-Farast-AI-Provider':'ollama-local','X-Farast-AI-Model':MODEL};
    res.writeHead(upstream.status,headers);
    if(upstream.body)Readable.fromWeb(upstream.body).pipe(res);else res.end();
  }catch(error){
    if(!res.headersSent)json(res,503,{error:{message:'local_llm_unavailable'}});
    else res.end();
  }
});
server.listen(PORT,HOST,()=>process.stdout.write(`FARAST private local AI gateway listening on ${HOST}:${PORT}; model=${MODEL}\n`));
