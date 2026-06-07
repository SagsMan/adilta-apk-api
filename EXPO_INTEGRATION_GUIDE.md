# Expo Push Notification Integration Guide
# For the AdilData React Native App

---

## What the Expo developer needs to do

### 1. Install packages

```bash
npx expo install expo-notifications expo-device expo-constants
```

### 2. Configure app.json / app.config.js

Add Firebase config to your `app.json`:

```json
{
  "expo": {
    "name": "AdilData",
    "plugins": [
      [
        "expo-notifications",
        {
          "icon": "./assets/notification-icon.png",
          "color": "#ffffff",
          "sounds": []
        }
      ]
    ],
    "android": {
      "googleServicesFile": "./google-services.json",
      "package": "com.adildata.app"
    },
    "ios": {
      "googleServicesFile": "./GoogleService-Info.plist",
      "bundleIdentifier": "com.adildata.app"
    }
  }
}
```

### 3. Add the Firebase config files

- **Android:** Download `google-services.json` from Firebase Console → Project Settings → Android App → Download
- **iOS:** Download `GoogleService-Info.plist` from Firebase Console → Project Settings → iOS App → Download

Place both files in the root of the Expo project.

### 4. Add this code to your app (e.g. in a `usePushNotifications.ts` hook)

```typescript
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import Constants from 'expo-constants';
import { Platform, Alert } from 'react-native';
import { useEffect, useRef } from 'react';

const API_BASE = 'https://api.adildata.com.ng';

// Configure how notifications appear when app is in foreground
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowAlert: true,
    shouldPlaySound: true,
    shouldSetBadge: false,
  }),
});

export function usePushNotifications(userToken: string | null) {
  const notificationListener = useRef<any>();
  const responseListener = useRef<any>();

  async function registerForPushNotifications() {
    if (!Device.isDevice) return; // Push doesn't work on simulator

    // Request permission
    const { status: existingStatus } = await Notifications.getPermissionsAsync();
    let finalStatus = existingStatus;

    if (existingStatus !== 'granted') {
      const { status } = await Notifications.requestPermissionsAsync();
      finalStatus = status;
    }

    if (finalStatus !== 'granted') {
      console.log('Push notification permission denied');
      return;
    }

    // Android channel
    if (Platform.OS === 'android') {
      await Notifications.setNotificationChannelAsync('default', {
        name: 'default',
        importance: Notifications.AndroidImportance.MAX,
        vibrationPattern: [0, 250, 250, 250],
        lightColor: '#FF231F7C',
      });
    }

    // Get the FCM token
    const projectId = Constants.expoConfig?.extra?.eas?.projectId;
    const tokenData = await Notifications.getExpoPushTokenAsync({ projectId });
    const fcmToken = tokenData.data;

    console.log('FCM Token:', fcmToken);

    // Save token to your backend
    if (userToken) {
      await saveTokenToBackend(fcmToken, userToken);
    }

    return fcmToken;
  }

  async function saveTokenToBackend(fcmToken: string, authToken: string) {
    try {
      await fetch(`${API_BASE}/saveDeviceToken.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          token: authToken,       // user's auth token from login
          fcm_token: fcmToken,    // firebase push token
          platform: Platform.OS,  // 'android' or 'ios'
        }),
      });
    } catch (err) {
      console.error('Failed to save push token:', err);
    }
  }

  useEffect(() => {
    if (!userToken) return;

    registerForPushNotifications();

    // Handle notification received while app is open
    notificationListener.current = Notifications.addNotificationReceivedListener(notification => {
      console.log('Notification received:', notification);
    });

    // Handle user tapping on a notification
    responseListener.current = Notifications.addNotificationResponseReceivedListener(response => {
      const data = response.notification.request.content.data;

      // Navigate to the relevant screen
      if (data?.screen === 'Wallet') {
        // navigation.navigate('Wallet');  // Use your navigation ref here
      }
    });

    return () => {
      Notifications.removeNotificationSubscription(notificationListener.current);
      Notifications.removeNotificationSubscription(responseListener.current);
    };
  }, [userToken]);
}
```

### 5. Call the hook after login

In your main screen or auth context:

```typescript
import { usePushNotifications } from './hooks/usePushNotifications';

function App() {
  const { userToken } = useAuth(); // your existing auth context
  usePushNotifications(userToken);

  // rest of your app...
}
```

---

## Keys / Credentials the developer needs from Firebase

| What | Where to get it | Used for |
|------|----------------|----------|
| `google-services.json` | Firebase Console → Project Settings → Android App | FCM on Android |
| `GoogleService-Info.plist` | Firebase Console → Project Settings → iOS App | FCM on iOS |
| Firebase Project ID | Firebase Console → Project Settings → General | Server-side (in fcm_helper.php) |
| Service Account JSON | Firebase Console → Project Settings → Service Accounts → Generate new private key | Server-side FCM sending |

---

## For EAS Build (production APK)

```bash
# Install EAS CLI
npm install -g eas-cli

# Configure build
eas build:configure

# Build APK
eas build --platform android --profile preview
```

Your `eas.json`:
```json
{
  "build": {
    "preview": {
      "android": { "buildType": "apk" }
    },
    "production": {}
  }
}
```
