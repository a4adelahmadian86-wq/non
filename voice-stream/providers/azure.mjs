import sdk from 'microsoft-cognitiveservices-speech-sdk';

export function createAzureStream(config, locale, handlers){
  const c=config.credentials||{};
  const key=String(c.key||c.subscription_key||c.subscriptionKey||'');
  const region=String(c.region||config.region||'');
  if(!key||!region)throw new Error('azure_credentials_missing');
  const format=sdk.AudioStreamFormat.getWaveFormatPCM(16000,16,1);
  const push=sdk.AudioInputStream.createPushStream(format);
  const audioConfig=sdk.AudioConfig.fromStreamInput(push);
  const speechConfig=sdk.SpeechConfig.fromSubscription(key,region);
  speechConfig.speechRecognitionLanguage=locale;
  speechConfig.outputFormat=sdk.OutputFormat.Simple;
  const recognizer=new sdk.SpeechRecognizer(speechConfig,audioConfig);
  recognizer.recognizing=(_,e)=>handlers.interim?.(String(e?.result?.text||''));
  recognizer.recognized=(_,e)=>{
    if(e?.result?.reason===sdk.ResultReason.RecognizedSpeech)handlers.final?.(String(e.result.text||''));
  };
  recognizer.canceled=(_,e)=>handlers.error?.(e);
  recognizer.startContinuousRecognitionAsync(()=>handlers.ready?.(),e=>handlers.error?.(e));
  return {write:buf=>push.write(buf),close:()=>{try{push.close()}catch{}try{recognizer.stopContinuousRecognitionAsync(()=>recognizer.close(),()=>recognizer.close())}catch{try{recognizer.close()}catch{}}}};
}
