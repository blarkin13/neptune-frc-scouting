/* Neptune Pit & Alliance: durable, per-account IndexedDB queue. */
(function(){'use strict';
  const DB='neptune-tag-pit-alliance-v1',STORE='submissions';
  function database(){return new Promise((resolve,reject)=>{
    if(!window.indexedDB){reject(Error('Offline storage is not supported on this browser.'));return;}
    const r=indexedDB.open(DB,1);
    r.onupgradeneeded=()=>{const d=r.result;if(!d.objectStoreNames.contains(STORE))d.createObjectStore(STORE,{keyPath:'key'});};
    r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error||Error('Offline database failed'));
  });}
  async function transaction(mode,fn){const db=await database();return new Promise((resolve,reject)=>{
    const tx=db.transaction(STORE,mode),store=tx.objectStore(STORE);let value;
    try{value=fn(store);}catch(e){tx.abort();reject(e);return;}
    tx.oncomplete=()=>{db.close();resolve(value);};tx.onerror=()=>{db.close();reject(tx.error||Error('Local save failed'));};tx.onabort=()=>{db.close();reject(tx.error||Error('Local storage transaction aborted'));};
  });}
  async function all(scope){const db=await database();return new Promise((resolve,reject)=>{
    const tx=db.transaction(STORE,'readonly');const r=tx.objectStore(STORE).getAll();
    r.onsuccess=()=>resolve((r.result||[]).filter(x=>x.scope===scope).sort((a,b)=>a.created-b.created));r.onerror=()=>reject(r.error);tx.oncomplete=()=>db.close();
  });}
  const locks=new Set();
  window.NeptuneTagOffline={
    enqueue:async(scope,submission)=>transaction('readwrite',store=>{store.put({...submission,scope,created:Date.now(),key:scope+':'+submission.uuid});}),
    count:async scope=>(await all(scope)).length,
    pending:async scope=>all(scope),
    flush:async(scope,send)=>{
      if(locks.has(scope))return;locks.add(scope);
      try{for(const item of await all(scope)){
        try{await send(item);await transaction('readwrite',store=>{store.delete(item.key);});}
        catch(e){break;}
      }}finally{locks.delete(scope);}
    }
  };
})();
