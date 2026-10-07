/* Neptune QR: fixed QR Version 4 / Error Correction L (up to 78 UTF-8 bytes). */
(function(root,factory){const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;else root.NeptuneQR=api;})(typeof globalThis!=='undefined'?globalThis:this,function(){
  const SIZE=33, DATA_CW=80, EC_CW=20;
  const exp=new Array(512), log=new Array(256);let x=1;
  for(let i=0;i<255;i++){exp[i]=x;log[x]=i;x<<=1;if(x&0x100)x^=0x11d;}for(let i=255;i<512;i++)exp[i]=exp[i-255];
  const mul=(a,b)=>a&&b?exp[log[a]+log[b]]:0;
  function polyMul(a,b){const out=new Array(a.length+b.length-1).fill(0);for(let i=0;i<a.length;i++)for(let j=0;j<b.length;j++)out[i+j]^=mul(a[i],b[j]);return out;}
  function rs(data,n){let gen=[1];for(let i=0;i<n;i++)gen=polyMul(gen,[1,exp[i]]);const msg=data.concat(new Array(n).fill(0));for(let i=0;i<data.length;i++){const f=msg[i];if(!f)continue;for(let j=0;j<gen.length;j++)msg[i+j]^=mul(gen[j],f);}return msg.slice(data.length);}
  function utf8(s){return Array.from(new TextEncoder().encode(s));}
  function pushBits(bits,val,len){for(let i=len-1;i>=0;i--)bits.push((val>>>i)&1);}
  function dataCodewords(text){const bytes=utf8(text);if(bytes.length>78)throw new Error('QR text is too long for Neptune QR v4-L.');const bits=[];pushBits(bits,0b0100,4);pushBits(bits,bytes.length,8);bytes.forEach(b=>pushBits(bits,b,8));const cap=DATA_CW*8;for(let i=0;i<4&&bits.length<cap;i++)bits.push(0);while(bits.length%8)bits.push(0);const out=[];for(let i=0;i<bits.length;i+=8){let b=0;for(let j=0;j<8;j++)b=(b<<1)|(bits[i+j]||0);out.push(b);}for(let pad=0;out.length<DATA_CW;pad++)out.push(pad%2?0x11:0xec);return out;}
  function finder(m,row,col){for(let r=-1;r<=7;r++)for(let c=-1;c<=7;c++){const y=row+r,x=col+c;if(y<0||x<0||y>=SIZE||x>=SIZE)continue;let dark=false;if(r>=0&&r<=6&&c>=0&&c<=6)dark=(r===0||r===6||c===0||c===6||(r>=2&&r<=4&&c>=2&&c<=4));m[y][x]=dark;}}
  function alignment(m,cy,cx){for(let r=-2;r<=2;r++)for(let c=-2;c<=2;c++){const d=Math.max(Math.abs(r),Math.abs(c));m[cy+r][cx+c]=(d===2||d===0);}}
  function bchDigit(v){let d=0;while(v){d++;v>>>=1;}return d;}
  function formatBits(mask){const G=0x537,M=0x5412;const data=(1<<3)|mask;let d=data<<10;while(bchDigit(d)-bchDigit(G)>=0)d^=G<<(bchDigit(d)-bchDigit(G));return ((data<<10)|d)^M;}
  function writeFormat(m,bits){for(let i=0;i<15;i++){const mod=((bits>>i)&1)!==0;if(i<6)m[i][8]=mod;else if(i<8)m[i+1][8]=mod;else m[SIZE-15+i][8]=mod;}for(let i=0;i<15;i++){const mod=((bits>>i)&1)!==0;if(i<8)m[8][SIZE-i-1]=mod;else if(i<9)m[8][15-i-1+1]=mod;else m[8][15-i-1]=mod;}m[SIZE-8][8]=true;}
  function reserveFormat(m){writeFormat(m,0);}
  function matrix(text){const data=dataCodewords(text), code=data.concat(rs(data,EC_CW)), bits=[];code.forEach(b=>pushBits(bits,b,8));const m=Array.from({length:SIZE},()=>Array(SIZE).fill(null));finder(m,0,0);finder(m,0,SIZE-7);finder(m,SIZE-7,0);alignment(m,26,26);for(let i=8;i<SIZE-8;i++){if(m[6][i]===null)m[6][i]=(i%2===0);if(m[i][6]===null)m[i][6]=(i%2===0);}reserveFormat(m);m[SIZE-8][8]=true;
    let bit=0,up=true;for(let col=SIZE-1;col>0;col-=2){if(col===6)col--;for(let n=0;n<SIZE;n++){const row=up?(SIZE-1-n):n;for(let dx=0;dx<2;dx++){const c=col-dx;if(m[row][c]!==null)continue;let dark=bit<bits.length?bits[bit++]===1:false;if(((row+c)&1)===0)dark=!dark;m[row][c]=dark;}}up=!up;}writeFormat(m,formatBits(0));return m;}
  function draw(canvas,text,opts){opts=opts||{};const m=matrix(text),quiet=Number(opts.quiet??4),scale=Math.max(2,Number(opts.scale??8)),modules=SIZE+quiet*2,size=modules*scale;canvas.width=size;canvas.height=size;const ctx=canvas.getContext('2d');ctx.imageSmoothingEnabled=false;ctx.fillStyle=opts.light||'#fff';ctx.fillRect(0,0,size,size);ctx.fillStyle=opts.dark||'#000';for(let r=0;r<SIZE;r++)for(let c=0;c<SIZE;c++)if(m[r][c])ctx.fillRect((c+quiet)*scale,(r+quiet)*scale,scale,scale);return canvas;}
  return {matrix,draw,size:SIZE};
});
