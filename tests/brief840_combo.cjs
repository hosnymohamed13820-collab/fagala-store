/* اختبار معزول للمكوّن الحقيقي، بواجهة DOM صغيرة من غير حزم خارجية. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
class El {
    constructor(){this.children=[];this.listeners={};this.attrs={};this.value='';this.hidden=false;this.style={};this.classList={toggle(){}};}
    setAttribute(k,v){this.attrs[k]=v;}
    getAttribute(k){return this.attrs[k];}
    removeAttribute(k){delete this.attrs[k];}
    appendChild(el){this.children.push(el);return el;}
    replaceChildren(){this.children=[];}
    addEventListener(k,fn){(this.listeners[k]??=[]).push(fn);}
    removeEventListener(k,fn){this.listeners[k]=(this.listeners[k]||[]).filter(f=>f!==fn);}
    dispatch(k,opts={}){const e={key:'',target:this,preventDefault(){this.defaultPrevented=true;},stopPropagation(){this.stopped=true;},...opts};(this.listeners[k]||[]).forEach(fn=>fn(e));return e;}
    getBoundingClientRect(){return {left:10,bottom:40,width:150};}
    get offsetHeight(){return 100;}
    scrollIntoView(){}
    closest(){return null;}
    contains(el){return this.children.includes(el);}
    remove(){}
}
const body=new El(),window=new El();window.innerWidth=1000;window.innerHeight=800;window.ui={};
const document={body,createElement:()=>new El(),documentElement:{dir:'rtl'}};
const source=fs.readFileSync('site/assets/app.js','utf8');
const start=source.lastIndexOf('(function () {');
vm.runInNewContext(source.slice(start),{window,document});
const input=new El();input.value='ورق وتصوير';let picks=[];
const combo=window.ui.combo(input,['أدوات مدرسية','أدوات مكتبية','ورق وتصوير'],{onPick:o=>picks.push(o)});
const list=body.children[0];
input.value='ادوات م';input.dispatch('input');
assert.equal(list.children.length,2);assert.match(list.children[0].textContent,/^أدوات م/);assert(!list.children.some(c=>c.textContent==='ورق وتصوير'));
input.dispatch('keydown',{key:'Escape'});assert.equal(input.value,'ورق وتصوير');assert(list.hidden);assert.equal(picks.length,0);
input.dispatch('keydown',{key:'ArrowDown',ctrlKey:true});assert(!list.hidden);assert.equal(list.children.length,3);
let e=input.dispatch('keydown',{key:'ArrowDown'});assert(e.stopped&&e.defaultPrevented);
input.dispatch('keydown',{key:'Enter'});assert.equal(picks.length,1);assert(list.hidden);
input.value='مش تصنيف';input.dispatch('input');input.dispatch('blur');assert.equal(input.value,picks[0].name);
input.value='ادوات مكتبيه';input.dispatch('blur');assert.equal(input.value,'أدوات مكتبية');
input.dispatch('keydown',{key:'ArrowDown',altKey:true});assert.equal(list.children.length,3);
window.dispatch('scroll',{target:list});assert(!list.hidden);
input.dispatch('keydown',{key:'Escape'});e=input.dispatch('keydown',{key:'ArrowDown'});assert(!e.stopped);
combo.destroy();
const digits=new El();digits.value='قديم';window.ui.combo(digits,['أدوات ١٢','ورق 34']);digits.value='ادوات 12';digits.dispatch('input');assert.equal(body.children[1].children.length,1);
console.log('PASS combo: Arabic filter, ranking, Esc, Ctrl/Alt+Down, arrows, blur, digits and scroll');
