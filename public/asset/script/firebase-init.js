import { initializeApp } from "https://www.gstatic.com/firebasejs/11.6.0/firebase-app.js";
import { getFirestore } from "https://www.gstatic.com/firebasejs/11.6.0/firebase-firestore.js";

// Replace only this portion  -- Start
// Your web app's Firebase configuration
// For Firebase JS SDK v7.20.0 and later, measurementId is optional
const firebaseConfig = {
  apiKey: "AIzaSyCF_ihazwKTHcN8hSDrDeE3Y8vtrVIb1RA",
  authDomain: "chatter-f994a.firebaseapp.com",
  projectId: "chatter-f994a",
  storageBucket: "chatter-f994a.firebasestorage.app",
  messagingSenderId: "194921440303",
  appId: "1:194921440303:web:8f572276b54e740b28d2da",
  measurementId: "G-BEM376JGQ6"
};
// Replace only this portion -- End

const app = initializeApp(firebaseConfig);
const db = getFirestore(app);

export {app, db};