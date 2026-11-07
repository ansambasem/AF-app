import { before, beforeEach, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

let Paper, Designer, state;
before(async () => {
    const bundle = await rolldown({
        input: 'paper-preview-tests', platform: 'node', transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{ name: 'paper-preview', resolveId(id) {
            if (id === 'exceljs') return { id: import.meta.resolve(id), external: true };
            if (id === 'paper-preview-tests') return '\0entry';
            if (id === '@inertiajs/react') return '\0inertia';
            if (id.startsWith('file:')) return { id, external: true };
            if (/^react(?:-dom)?(?:\/|$)/.test(id)) return { id: import.meta.resolve(id), external: true };
            if (/Layouts[/\\]AuthenticatedLayout(?:\.jsx)?$/.test(id)) return '\0layout';
            if (/Components[/\\]PrimaryButton(?:\.jsx)?$/.test(id)) return '\0primary';
            if (id.endsWith('.css')) return '\0css';
        }, load(id) {
            if (id === '\0entry') return "export {default as Paper} from '@/Pages/ReadingCycles/PaperSheet'; export {default as Designer} from '@/Components/Print/PrintDesigner'; export {state} from '@inertiajs/react';";
            if (id === '\0css') return '';
            if (id === '\0layout') return "import {state} from '@inertiajs/react'; export default function Layout({children,beforePrint}){state.beforePrint=beforePrint;return children;}";
            if (id === '\0primary') return `import {createElement} from ${JSON.stringify(import.meta.resolve('react'))}; import {state} from '@inertiajs/react'; export default function Primary(props){if(props.className?.includes('tone-print'))state.printAction=props.onClick;return createElement('button',props,props.children);}`;
            if (id === '\0inertia') return `
                export const state={calls:[],beforePrint:null,printAction:null};
                export const Head=()=>null;
                export const router={get(){},post(){},reload(){}};
                export const usePage=()=>({props:{}});
                export const useHttp=()=>({post:async(url)=>{state.calls.push(url);return {printed:true};}});
                export const useForm=data=>({data,errors:{},processing:false,setData(){}});
                export const Link=()=>null;
            `;
        } }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm', codeSplitting: false });
        ({ Paper, Designer, state } = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`));
    } finally { await bundle.close(); }
});

beforeEach(() => {
    state.calls.length = 0;
    state.beforePrint = null;
    state.printAction = null;
    global.window = {location: {href:'http://127.0.0.1:8000/paper-sheets/1',pathname:'/paper-sheets/1'}, print:()=>state.calls.push('print')};
});

test('paper sheets group all boxes by area and keep phone under the subscriber with a blank current reading', async () => {
    const boxes = [1,2,3].map((id) => ({id,label:`طبلون (${900+id})`,subAreaName:id===3?'جنوب':'شمال',subscriptions:[{id,name:`مشترك ${id}`,phone:'0599000001',accountNumber:`A${id}`,previousReading:38}]}));
    const html = renderToStaticMarkup(createElement(Paper, {sheet:{id:1,number:'S-1',printCount:0},cycle:{id:1,branchName:'الفرع',readingDate:'2026-10-29',weekStart:'2026-10-23',weekEnd:'2026-10-29'},boxes}));
    assert.equal((html.match(/<table/g)??[]).length, 1);
    assert.equal((html.match(/منطقة 2: شمال/g)??[]).length, 1);
    assert.equal((html.match(/منطقة 2: جنوب/g)??[]).length, 1);
    for (const id of [1,2,3]) {
        const row = [...html.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)].find((match)=>match[1].includes(`مشترك ${id}`))[1];
        const cells = [...row.matchAll(/<td\b[^>]*>([\s\S]*?)<\/td>/g)].map((match)=>match[1]);
        assert.match(cells[1], new RegExp(`${900+id}`));
        assert.match(cells[2], /<span[^>]*>مشترك.*<\/span><bdi[^>]*>0599000001<\/bdi>/);
        assert.equal(cells[4], '38');
        assert.equal(cells[5], '');
    }
    assert.deepEqual(state.calls, []);
    await state.beforePrint();
    assert.deepEqual(state.calls, ['/paper-sheets/1/printed']);
});

test('the designer waits for print preparation and blocks duplicate clicks while it is pending', async () => {
    let finish;
    const pending = new Promise(resolve=>finish=resolve);
    renderToStaticMarkup(createElement(Designer,{settings:{title:'الكشف',allRows:false},beforePrint:()=>{state.calls.push('prepare');return pending;}}));
    const first = state.printAction();
    await state.printAction();
    assert.deepEqual(state.calls, ['prepare']);
    finish();
    await first;
    assert.deepEqual(state.calls, ['prepare','print']);
});

test('failed print preparation does not open a printable sheet or count another attempt', async () => {
    renderToStaticMarkup(createElement(Designer,{settings:{title:'الكشف',allRows:false},beforePrint:async()=>{throw new Error('Offline');}}));
    await state.printAction();
    assert.deepEqual(state.calls, []);
});
