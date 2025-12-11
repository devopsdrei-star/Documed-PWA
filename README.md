# Getting Started with Create React App

This project was bootstrapped with [Create React App](https://github.com/facebook/create-react-app).

## Available Scripts

In the project directory, you can run:

### `npm start`

Runs the app in the development mode.\
Open [http://localhost:3000](http://localhost:3000) to view it in your browser.

The page will reload when you make changes.\
You may also see any lint errors in the console.

### `npm test`

Launches the test runner in the interactive watch mode.\
See the section about [running tests](https://facebook.github.io/create-react-app/docs/running-tests) for more information.

### `npm run build`

Builds the app for production to the `build` folder.\
It correctly bundles React in production mode and optimizes the build for the best performance.

The build is minified and the filenames include the hashes.\
Your app is ready to be deployed!

See the section about [deployment](https://facebook.github.io/create-react-app/docs/deployment) for more information.

### `npm run eject`

**Note: this is a one-way operation. Once you `eject`, you can't go back!**

If you aren't satisfied with the build tool and configuration choices, you can `eject` at any time. This command will remove the single build dependency from your project.

Instead, it will copy all the configuration files and the transitive dependencies (webpack, Babel, ESLint, etc) right into your project so you have full control over them. All of the commands except `eject` will still work, but they will point to the copied scripts so you can tweak them. At this point you're on your own.

You don't have to ever use `eject`. The curated feature set is suitable for small and middle deployments, and you shouldn't feel obligated to use this feature. However we understand that this tool wouldn't be useful if you couldn't customize it when you are ready for it.

## Learn More

You can learn more in the [Create React App documentation](https://facebook.github.io/create-react-app/docs/getting-started).

To learn React, check out the [React documentation](https://reactjs.org/).

### Code Splitting

This section has moved here: [https://facebook.github.io/create-react-app/docs/code-splitting](https://facebook.github.io/create-react-app/docs/code-splitting)

### Analyzing the Bundle Size

This section has moved here: [https://facebook.github.io/create-react-app/docs/analyzing-the-bundle-size](https://facebook.github.io/create-react-app/docs/analyzing-the-bundle-size)

### Making a Progressive Web App

This section has moved here: [https://facebook.github.io/create-react-app/docs/making-a-progressive-web-app](https://facebook.github.io/create-react-app/docs/making-a-progressive-web-app)

### Advanced Configuration

This section has moved here: [https://facebook.github.io/create-react-app/docs/advanced-configuration](https://facebook.github.io/create-react-app/docs/advanced-configuration)

### Deployment

This section has moved here: [https://facebook.github.io/create-react-app/docs/deployment](https://facebook.github.io/create-react-app/docs/deployment)

### `npm run build` fails to minify

This section has moved here: [https://facebook.github.io/create-react-app/docs/troubleshooting#npm-run-build-fails-to-minify](https://facebook.github.io/create-react-app/docs/troubleshooting#npm-run-build-fails-to-minify)




---

## DocuMed PWA (user side) quick guide

This workspace contains a traditional PHP/HTML frontend under `frontend/` and a React app in `src/` (not yet wired). The user-side PWA features live under `frontend/user/*` and are installable when served over HTTPS or `http://localhost`.

### Where to open the user dashboard

```
http://localhost:8080/DocMed/documed_pwa/frontend/user/user_dashboard.html
```

### Install prompt requirements

- Secure context: use HTTPS or `http://localhost`
- Manifest: `documed_pwa/manifest-landing.json`
- Service Worker: `documed_pwa/service-worker.js`

### Test over HTTPS quickly (Cloudflare Tunnel)

1. Ensure your local server is running on port 8080 and the dashboard URL above works on desktop.
2. Start a tunnel in PowerShell:
   
	```powershell
	C:\Tools\cloudflared.exe tunnel --url http://localhost:8080
	```
   
3. Open the HTTPS URL it prints (e.g., `https://<random>.trycloudflare.com`) with the dashboard path appended:
   
	```
	https://<random>.trycloudflare.com/DocMed/documed_pwa/frontend/user/user_dashboard.html
	```
   
4. On Android Chrome: load once (SW installs), refresh, then use the “Install App” button or Chrome menu > Install app.

### Mobile nav anchors

Top nav links (Services, About Us, Contact) now scroll smoothly to their sections using IDs `#services`, `#about`, and `#contact`. A scroll offset is applied so headings aren’t hidden behind the sticky nav.

### iOS note

iOS Safari doesn’t fire the `beforeinstallprompt` event. Use Share > Add to Home Screen to install.
