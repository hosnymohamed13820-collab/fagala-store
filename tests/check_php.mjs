// Node port of tests/check_php.py (python3 is unavailable on this machine)
import fs from 'fs';
const PAIRS = {'}':'{', ')':'(', ']':'['};
const ALT_OPEN = ['if','foreach','for','while','switch'];
const ALT_CLOSE = {endif:'if', endforeach:'foreach', endfor:'for', endwhile:'while', endswitch:'switch'};

function scan(path){
  const src = fs.readFileSync(path,'utf8');
  const errs=[]; let i=0; const n=src.length;
  let inPhp=false, stack=[], alt=[], line=1, openTags=0;
  while(i<n){
    const ch=src[i];
    if(ch==='\n') line++;
    if(!inPhp){
      if(src.startsWith('<?php',i)||src.startsWith('<?=',i)){
        inPhp=true; openTags++; i+= src.startsWith('<?php',i)?5:3; continue;
      }
      i++; continue;
    }
    if(src.startsWith('?>',i)){ inPhp=false; openTags--; i+=2; continue; }
    if(src.startsWith('//',i)||ch==='#'){
      let j=src.indexOf('\n',i); if(j<0) j=n;
      const seg=src.slice(i,j);
      if(seg.includes('?>')){
        const after=seg.split('?>')[1].trim();
        if(after===''){ inPhp=false; openTags--; }
        else errs.push(`سطر ${line}: ?> جوه تعليق // وبعده كود`);
      }
      i=j; continue;
    }
    if(src.startsWith('/*',i)){
      const j=src.indexOf('*/',i+2);
      if(j<0){ errs.push(`سطر ${line}: تعليق /* مش مقفول`); break; }
      line += (src.slice(i,j).match(/\n/g)||[]).length; i=j+2; continue;
    }
    if(ch==='"'||ch==="'"){
      const q=ch; let j=i+1;
      while(j<n){
        if(src[j]===String.fromCharCode(92)){ j+=2; continue; }
        if(src[j]===q) break;
        if(src[j]==='\n') line++;
        j++;
      }
      if(j>=n){ errs.push(`سطر ${line}: نص ${q} مش مقفول`); break; }
      i=j+1; continue;
    }
    const m=/^<<<\s*(['"]?)([A-Za-z_]\w*)\1\r?\n/.exec(src.slice(i));
    if(m){
      const tag=m[2];
      const rest=src.slice(i+m[0].length);
      const end=new RegExp("^[ \t]*"+tag+"\\b","m").exec(rest);
      if(!end){ errs.push(`سطر ${line}: heredoc ${tag} مش مقفول`); break; }
      const consumed=m[0].length+end.index+end[0].length;
      line += (src.slice(i,i+consumed).match(/\n/g)||[]).length;
      i+=consumed; continue;
    }
    if('{(['.includes(ch)){ stack.push([ch,line]); i++; continue; }
    if('})]'.includes(ch)){
      if(!stack.length) errs.push(`سطر ${line}: قوس زيادة ${ch}`);
      else if(stack[stack.length-1][0]!==PAIRS[ch]){
        const [o,ol]=stack[stack.length-1];
        errs.push(`سطر ${line}: ${ch} مش متطابق مع ${o} (سطر ${ol})`); stack.pop();
      } else stack.pop();
      i++; continue;
    }
    const w=/^[A-Za-z_]\w*/.exec(src.slice(i));
    if(w){
      const word=w[0], low=word.toLowerCase();
      if(low in ALT_CLOSE){
        if(!alt.length) errs.push(`سطر ${line}: ${low} من غير فتح`);
        else if(alt[alt.length-1][0]!==ALT_CLOSE[low]){
          errs.push(`سطر ${line}: ${low} والمفتوح ${alt[alt.length-1][0]} (سطر ${alt[alt.length-1][1]})`); alt.pop();
        } else alt.pop();
      } else if(ALT_OPEN.includes(low)){
        let j=i+word.length;
        while(j<n && ' \t\r\n'.includes(src[j])) j++;
        if(j<n && src[j]==='('){
          let d=0,k=j;
          while(k<n){ if(src[k]==='(') d++; else if(src[k]===')'){ d--; if(d===0) break; } k++; }
          k++;
          while(k<n && ' \t\r\n'.includes(src[k])) k++;
          if(k<n && src[k]===':') alt.push([low,line]);
        }
      }
      i+=word.length; continue;
    }
    i++;
  }
  for(const [o,ol] of stack) errs.push(`سطر ${ol}: ${o} مفتوح ومش مقفول`);
  for(const [a,al] of alt) errs.push(`سطر ${al}: ${a}: مفتوح ومش مقفول (ناقص end${a})`);
  if(openTags<0) errs.push(`?> زيادة (${-openTags})`);
  else if(openTags>1) errs.push(`${openTags} وسم <?php مفتوح ومش مقفول`);
  return errs;
}
let ok=true;
for(const p of process.argv.slice(2)){
  const e=scan(p);
  if(e.length){ ok=false; console.log('  ❌ '+p); e.forEach(x=>console.log('     '+x)); }
  else console.log('  ✅ '+p+' — البنية سليمة');
}
process.exit(ok?0:1);
