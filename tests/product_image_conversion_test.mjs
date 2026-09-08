import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';

const source=fs.readFileSync(new URL('../src/pages/productImageConversion.ts',import.meta.url),'utf8');
const compiled=ts.transpileModule(source,{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2020}}).outputText;
class TestFile extends Blob { constructor(parts,name,options={}){super(parts,options);this.name=name;this.lastModified=options.lastModified||0;} }
let bitmapClosed=false;
const context={
  module:{exports:{}}, exports:{}, File:TestFile, Blob,
  createImageBitmap:async()=>({width:200,height:100,close:()=>{bitmapClosed=true;}}),
  document:{createElement:()=>({width:0,height:0,getContext:()=>({drawImage(){}}),toBlob:(callback)=>callback(new Blob(['webp'],{type:'image/webp'}))})},
};
context.exports=context.module.exports;
vm.runInNewContext(compiled,context,{filename:'productImageConversion.ts'});
const {convertProductImageForUpload,isAvifImage}=context.module.exports;
const avif=new TestFile(['avif'],'television.avif',{type:'image/avif',lastModified:123});
assert.equal(isAvifImage(avif),true);
const converted=await convertProductImageForUpload(avif);
assert.equal(converted.name,'television.webp'); assert.equal(converted.type,'image/webp'); assert.equal(converted.lastModified,123); assert.equal(bitmapClosed,true);
for(const [name,type] of [['tv.jpg','image/jpeg'],['tv.png','image/png'],['tv.webp','image/webp']]) { const file=new TestFile(['image'],name,{type}); assert.equal(await convertProductImageForUpload(file),file,`${type} remains unchanged`); }
console.log('PASS AVIF conversion and JPG/PNG/WebP passthrough');
