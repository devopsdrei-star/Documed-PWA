import React from 'react';
import ReactDOM from 'react-dom/client';
import './index.css';
import App from './App';
import reportWebVitals from './reportWebVitals';

const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);

// Register service worker for PWA install/offline support
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    // If served from subdirectory (e.g. /documed_pwa/) build correct URL
    const base = window.location.pathname.includes('/documed_pwa/') ? '/documed_pwa' : '';
    const swUrl = `${base}/service-worker.js`;
    navigator.serviceWorker.register(swUrl).then(reg => {
      console.log('[SW] registered', reg.scope);
      // listen for updates
      reg.onupdatefound = () => {
        const installing = reg.installing;
        if (installing) {
          installing.onstatechange = () => {
            if (installing.state === 'installed') {
              if (navigator.serviceWorker.controller) {
                console.log('[SW] New content available; please refresh.');
              } else {
                console.log('[SW] Content cached for offline use.');
              }
            }
          };
        }
      };
    }).catch(err => console.error('[SW] registration failed', err));
  });
}

// If you want to start measuring performance in your app, pass a function
// to log results (for example: reportWebVitals(console.log))
// or send to an analytics endpoint. Learn more: https://bit.ly/CRA-vitals
reportWebVitals();
