const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
function client(kind,response){
 const button={disabled:false},status={textContent:''},file={size:20,type:'application/pdf',name:'Policy.pdf'};
 let submit;
 const elements={document:{files:[file]},title:{value:'Policy'},change_summary:{value:'New version'},department:{readOnly:false}};
 const form={elements,dataset:{version:'0'},reportValidity:()=>true,addEventListener(type,handler){submit=handler;},querySelector:()=>button};
 const token={content:'test-token'},newButton={};
 const document={getElementById:id=>id==='policy-upload'?form:status,querySelector(selector){
  if(selector==='#dc-attachment-form')return form;
  if(selector==='#dc-upload-message')return status;
  if(selector==='#dc-attachment-file')return {files:[file]};
  if(selector==='#dc-upload-new')return newButton;
  if(selector.includes('csrf-token'))return token;
  throw Error(selector);
 },querySelectorAll:()=>[]};
 const locations=[];
 const c={document,location:{assign:url=>locations.push(url)},crypto:{randomUUID:()=> '7eb5d508-9337-4e2d-8a56-b07bd50e1108'},FileReader:class{readAsDataURL(){this.result='data:application/pdf;base64,JVBERi0xLjQ=';this.onload();}},FormData:class{constructor(){return [['kind','pedoman'],['document_id',''],['title','Policy']];}},fetch:async()=>({ok:true,status:200,json:async()=>response})};
 vm.createContext(c);vm.runInContext(fs.readFileSync('public/js/'+kind+'.js','utf8'),c);
 return {status,button,locations,submit:()=> (submit||form.onsubmit)({preventDefault(){}})};
}
for(const kind of ['policy-documents','document-attachments']){
 for(const response of [{},{received:0},{id:1},{id:0,redirect:'/policy'}])test(`${kind} does not claim success for an incomplete response ${JSON.stringify(response)}`,async()=>{
  const c=client(kind,response);await c.submit();assert.equal(c.locations.length,0);assert.match(c.status.textContent,/tidak memastikan dokumen tersimpan/);assert.equal(c.button.disabled,false);
 });
 test(`${kind} redirects only after acknowledged upload`,async()=>{
  const c=client(kind,{id:1,version:1,redirect:'/policy'});await c.submit();assert.deepEqual(c.locations,['/policy']);
 });
}
