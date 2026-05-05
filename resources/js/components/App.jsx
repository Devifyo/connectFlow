import React, { useState } from 'react';

function App() {
    const [executing, setExecuting] = useState(false);

    const steps = [
        {
            id: 1,
            title: "Ensure required Laravel framework directories exist.",
            code: "storage/framework/sessions\nstorage/framework/views\nstorage/framework/cache"
        },
        {
            id: 2,
            title: "Grant the web server user ownership of the storage and bootstrap cache directories.",
            code: "chown -R www-data:www-data storage bootstrap/cache"
        },
        {
            id: 3,
            title: "Ensure the web server has read, write, and execute permissions.",
            code: "chmod -R 775 storage bootstrap/cache"
        },
        {
            id: 4,
            title: "Clear stale compiled views and application cache.",
            code: "php artisan view:clear\nphp artisan cache:clear"
        }
    ];

    const handleExecute = () => {
        setExecuting(true);
        setTimeout(() => {
            setExecuting(false);
            alert("Fix executed successfully!");
        }, 2000);
    };

    return (
        <div className="min-h-screen bg-slate-900 bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 flex items-center justify-center p-6 text-slate-100 font-sans">
            <div className="max-w-3xl w-full backdrop-blur-md bg-white/10 border border-white/20 shadow-2xl rounded-2xl p-8">
                
                <div className="flex items-center justify-between mb-2">
                    <h1 className="text-2xl font-semibold tracking-tight text-white">Fix Laravel Storage Permissions</h1>
                    <span className="bg-blue-500/20 text-blue-300 border border-blue-500/30 rounded-full px-3 py-1 text-xs font-medium uppercase tracking-wider">
                        System Task
                    </span>
                </div>
                <p className="text-slate-300 text-sm mb-6">Resolves Laravel ViewException 'tempnam(): file created in system temporary directory' by recreating missing framework directories and applying correct web server permissions.</p>
                
                <div className="mt-6 space-y-4">
                    {steps.map(step => (
                        <div key={step.id} className="flex items-start gap-4 p-5 rounded-xl bg-white/5 hover:bg-white/10 transition-colors duration-300 border border-transparent hover:border-white/10 shadow-sm">
                            <div className="text-indigo-400 mt-0.5 bg-indigo-500/10 rounded-full w-8 h-8 flex items-center justify-center font-bold border border-indigo-500/20 flex-shrink-0">
                                {step.id}
                            </div>
                            <div className="w-full">
                                <p className="text-slate-200 font-medium">{step.title}</p>
                                <pre className="font-mono text-xs bg-slate-900/60 text-emerald-400 p-3 rounded-lg mt-3 overflow-x-auto border border-white/5">
                                    {step.code}
                                </pre>
                            </div>
                        </div>
                    ))}
                </div>

                <div className="mt-8 flex justify-end">
                    <button 
                        onClick={handleExecute}
                        disabled={executing}
                        className={`w-full md:w-auto bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 text-white font-medium py-3 px-8 rounded-lg shadow-md hover:shadow-lg transition-all duration-300 transform ${executing ? 'opacity-75 cursor-not-allowed animate-pulse' : 'hover:-translate-y-0.5'}`}
                    >
                        {executing ? 'Executing Fix...' : 'Execute Fix'}
                    </button>
                </div>
            </div>
        </div>
    );
}

export default App;