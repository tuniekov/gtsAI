import fs from 'fs';
import config from "./config.js";
import prompt from 'prompt';
import replace from 'replace-in-file'
prompt.start();

prompt.get(['packagename'], function (err, result) {
    if (err) { return onErr(err); }
    // console.log('Command-line input received:');
    console.log('Копируем пакет в папку ../' + result.packagename);
    fs.cpSync(".", "../" + result.packagename, { 
        recursive: true,
        filter: (src) => {
            // console.log('src',src)
            if(src.indexOf("\\node_modules") != -1) return false
            if(src.indexOf("\\.gitignore") != -1) return true
            if(src.indexOf("\\.git") != -1) return false
            if(src.indexOf("node_modules") != -1) return false
            if(src.indexOf(".gitignore") != -1) return true
            if(src.indexOf(".git") != -1) return false
            return true
        },
    });
    // console.log('Удаляем ../' + result.packagename + '/.git/');
    // fs.rmSync('../' + result.packagename + '/.git/', { recursive: true, force: true });
    // const re = new RegExp("\\w+\\s", "g");
    const options = {    
        //Glob(s) 
        files: [
            '../' + result.packagename + '/_build/configs/*',
            '../' + result.packagename + '/_build/config.js',
            '../' + result.packagename + '/src/main.js',
            '../' + result.packagename + '/assets/*',
            '../' + result.packagename + '/assets/**/*',
            '../' + result.packagename + '/core/*',
            '../' + result.packagename + '/core/**/*',
            '../' + result.packagename + '/public/checkdebug.txt',
            '../' + result.packagename + '/.env',
            '../' + result.packagename + '/.json',
            '../' + result.packagename + '/src/*',
            '../' + result.packagename + '/readme.md',
            '../' + result.packagename + '/index.html',
            '../' + result.packagename + '/package.json',
        ],
      
        //Replacement to make (string or regex) 
        from: [new RegExp(config.name, "g"),new RegExp(config.name_lower, "g")],
        to: [result.packagename,result.packagename.toLowerCase()],
    };
    console.log('Заменяем имя пакета в ../' + result.packagename);
    try {
        let changedFiles = replace.sync(options);
        console.log('Modified files:', changedFiles);
    }
        catch (error) {
        console.error('Error occurred:', error);
    }

    console.log('Переименовываем '+config.name_lower+'.class.php  в '+result.packagename.toLowerCase() + '.class.php');
    fs.rename(
        '../' + result.packagename + '/core/components/'+config.name_lower+'/model/' + config.name_lower + '.class.php',
        '../' + result.packagename + '/core/components/'+result.packagename.toLowerCase()+'/model/' + result.packagename.toLowerCase() + '.class.php',
    () => {
        console.log("Переименовали!" + config.name_lower + '.class.php');
    });
    fs.rename(
        '../' + result.packagename + '/core/components/'+config.name_lower+'/model/schema/' + config.name_lower + '.mysql.schema.xml',
        '../' + result.packagename + '/core/components/'+result.packagename.toLowerCase()+'/model/schema/' + result.packagename.toLowerCase() + '.mysql.schema.xml',
    () => {
        console.log("Переименовали!" + config.name_lower + '.mysql.schema.xml');
    });
    fs.rename(
        '../' + result.packagename + '/core/components/'+config.name_lower,
        '../' + result.packagename + '/core/components/'+result.packagename.toLowerCase(),
    () => {
        console.log("Переименовали! core");
    });
    fs.rename(
        '../' + result.packagename + '/assets/components/'+config.name_lower,
        '../' + result.packagename + '/assets/components/'+result.packagename.toLowerCase(),
    () => {
        console.log("Переименовали! assets");
    });
});

function onErr(err) {
    console.log(err);
    return 1;
}
