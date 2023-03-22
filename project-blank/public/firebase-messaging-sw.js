/* eslint-disable no-restricted-globals */
/* eslint-disable no-undef */
// import firebase scripts inside service worker js script
importScripts('https://...');
importScripts('https://...');


firebase.initializeApp({
  messagingSenderId: '...'
});

const messaging = firebase.messaging();

// If you would like to customize notifications that are received in the
// background (Web app is closed or not in browser focus) then you should
// implement this optional method.
// [START background_handler]
messaging.setBackgroundMessageHandler((payload) => {
  console.log('[firebase-messaging-sw.js] Received background message ', payload);
  setTimeout(() => {
    // Customize notification here
    const notificationTitle = 'Nueva orden';
    const notificationOptions = {
      body: 'Ha llegado una nueva orden de delivery',
      icon: 'https://...',
      data: {
        title: 'Testing'
      }
    };

    return self.registration.showNotification(notificationTitle,
      notificationOptions);
  }, 100);
});
// [END background_handler]

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  clients.openWindow("https://www.google.com/");
});
