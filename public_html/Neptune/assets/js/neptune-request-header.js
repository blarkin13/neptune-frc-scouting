/* Adds X-Neptune-Request: 1 to same-origin, non-GET fetches (see _json_request_guard.php).
   Loaded synchronously in <head> so later wrappers such as Connection Guard sit on top of it. */
(function(){
  if(!window.fetch||window.__neptuneRequestHeader)return;
  window.__neptuneRequestHeader=true;
  const nativeFetch=window.fetch.bind(window);
  window.fetch=function(input,init){
    try{
      const req=input instanceof Request?input:null;
      const url=new URL(req?req.url:String(input),location.href);
      const method=String((init&&init.method)||(req&&req.method)||'GET').toUpperCase();
      if(url.origin===location.origin&&method!=='GET'&&method!=='HEAD'){
        const headers=new Headers((init&&init.headers)||(req&&req.headers)||undefined);
        if(!headers.has('X-Neptune-Request'))headers.set('X-Neptune-Request','1');
        init=Object.assign({},init||{},{headers});
      }
    }catch(_){}
    return nativeFetch(input,init);
  };
})();
