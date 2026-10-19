console.log('upload config on site!');

import fs from 'fs/promises';
import axios from 'axios';
import config from "./config.js";
import chunks from "./configs/chunks.js";
import snippets from "./configs/snippets.js";
import plugins from "./configs/plugins.js";
import templates from "./configs/templates.js";
import settings from "./configs/settings.js";
import menus from "./configs/menus.js";
import gtsapipackages from "./configs/gtsapipackages.js"
import data from "./configs/data.js";
import dependencies from "./configs/dependencies.js";
import resources from "./configs/resources.js";
import resource_groups from "./configs/resource_groups.js";
import user_groups from "./configs/user_groups.js";
import permissions from "./configs/permissions.js";
import FormData from 'form-data';
import { loadEnv} from 'vite'

const mode ='development' //development
process.env = {...process.env,...loadEnv(mode, './'),...loadEnv(mode, '../')}
// console.log(config)
/**
 * Сборка для магазина: `npm run build:store`.
 *
 * ⚠️ Конфиги, разложенные по сайтам (страницы и засев данных), держат наши
 * площадки: ключи 'modx28' и 'vk24' с чужими id родителей и нашими чатами.
 * Покупателю это мусор: страница на его сайте не создастся (родителя 248 у
 * него нет), а настройки чата расскажут про наши таблицы. В магазинной
 * сборке оставляем только блок '*' - общий для любой установки, - а
 * страницы не создаём вовсе: куда поставить чат, решает владелец сайта
 * (вызов сниппета есть в readme пакета).
 */
const forStore = process.argv.includes('--store')
const onlyCommon = (obj) => {
    if (!forStore || !obj || typeof obj !== 'object') return obj
    const keys = Object.keys(obj)
    // Разложено по сайтам, если верхние ключи - метки площадок.
    if (!keys.length || !keys.some(k => k === '*' || k === 'modx28' || k === 'vk24')) return obj
    return obj['*'] ? { '*': obj['*'] } : {}
}
if (forStore) console.log('сборка для магазина: только общий блок, страницы не создаются')

const form = new FormData()
//console.log('process.env',process.env)        
// Магазинную сборку на этот сайт не ставим: в ней нет наших блоков, и
// установка затёрла бы ими же собранные настройки дева.
form.append('config', JSON.stringify(forStore ? { ...config, install: false } : config))

if(config.schema){
    try{
        // const file = await fs.readFile('./_build/configs/schema.xml')
        form.append('schema', 1)
    }catch(e){
        console.log('Ошибка файла', './_build/configs/schema.xml', e)
    }
}

// for(let k in snippets){
//     try{
//         const file = await fs.readFile('../core/components/'+config.name_lower+'/elements/snippets/' + snippets[k].file)
//         form.append(snippets[k].file, file, snippets[k].file)
//     }catch(e){
//         console.log('Ошибка файла', snippets[k].file, e)
//     }
// }
form.append('snippets', JSON.stringify(snippets))
form.append('plugins', JSON.stringify(plugins))
form.append('chunks', JSON.stringify(chunks))
form.append('templates', JSON.stringify(templates))
form.append('settings', JSON.stringify(settings))
form.append('menus', JSON.stringify(menus))
form.append('gtsapipackages', JSON.stringify(gtsapipackages, null, 2))
form.append('data', JSON.stringify(onlyCommon(data), null, 2))
form.append('dependencies', JSON.stringify(dependencies, null, 2))
form.append('resources', JSON.stringify(forStore ? {} : resources, null, 2))
form.append('resource_groups', JSON.stringify(resource_groups, null, 2))
form.append('user_groups', JSON.stringify(user_groups, null, 2))
form.append('permissions', JSON.stringify(permissions, null, 2))

const fileExists = async path => !!(await fs.stat(path).catch(e => false));
if(config.core){
    if (await fileExists(`${process.env.VITE_APP_CORE_DIR}`)) {
        await fs.cp(`${process.env.VITE_APP_CORE_DIR}`, `../../${process.env.VITE_APP_CORE_DIR}`, { recursive: true });
    }
}
if(config.assets){
    if (await fileExists(`${process.env.VITE_APP_ASSETS_DIR}`)) {
        await fs.cp(`${process.env.VITE_APP_ASSETS_DIR}`, `../../${process.env.VITE_APP_ASSETS_DIR}`, { recursive: true });
    }
}
let error = null;
try {
    const url = `${process.env.VITE_APP_PROTOCOL}://${process.env.VITE_APP_HOST}/api/package`
    //console.log('url',url)
    const res = await axios.post(url,form,{
        headers: { Authorization: `Bearer ${process.env.VITE_DEV_TOKEN}` }
    }).catch(err => {
        if (err.response && err.response.status === 404) {
            throw new Error(`${err.config.url} not found`);
        }
        throw err;
    });

    if(res.data.success){
        console.log(res.data.message)
    }else{
        console.log(res.data)
    }
    
}catch (err) {
    error = err;
}
if(error)
    console.log('error',error.message)
