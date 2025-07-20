# Laravel Chat

**Laravel Chat** adds rich, flexible chat functionality to your Laravel application with minimal setup and maximum customization. Whether you're building one-on-one messaging, group chatrooms, or bot integrations, this package gives you the tools to implement powerful conversations between any models in your app.

---

## 🚀 Features

* Send messages between any models
* One-to-one and multi-user chatrooms
* System messages and bot support
* Unread message tracking
* WebSocket connection detection
* Notifications via Laravel and WebPush
* REST API endpoints included
* No frontend views — bring your own UI

---

## 📦 Installation

```bash
composer require mmedia/laravel-chat
```

Publish migrations and config:

```bash
php artisan vendor:publish --tag="chat-migrations"
php artisan migrate

php artisan vendor:publish --tag="chat-config"
```

---

## 🧠 Core Concepts

* `Chatroom`: A conversation space containing messages and participants
* `ChatParticipant`: A polymorphic link between your models and a chatroom
* `Message`: A message sent by a participant in a chatroom
* `IsChatParticipant` Trait: Add to any model to enable chat behavior

---

## ✅ Requirements

* PHP 8.0+
* Laravel 11+

---

## ✨ Quick Example

```php
$message = $user->sendMessageTo($otherUser, "Hello!");
$reply = $otherUser->sendMessageTo($user, "Hi back!");
```

Easily send messages to:

* Individual users
* Multiple recipients
* Entire chatrooms
* Non-model participants (bots, services, etc.)

---

## 🔥 Why Not Chatify?

Unlike [Chatify](https://github.com/munafio/chatify), Laravel Chat **does not limit messages to one sender and one recipient**. This package supports:

* Multi-participant chatrooms
* Flexible polymorphic model support
* Bots and non-DB participants

---

## 📚 Full Documentation

See the complete usage guide for in-depth examples, APIs, and advanced features:

👉 [View Full Docs →](https://laravelchat.netlify.app/)

---

## 🧪 Comparison to Other Projects

* [Wirechat](https://github.com/namumakwembo/wirechat) — Livewire-heavy
* [Musonza Chat](https://github.com/musonza/chat) — Feature-rich but complex
* Laravel Chat — **Simple API, full flexibility, no view layer**

---

## 🙋‍♂️ Use Cases

* Teacher ↔ Student chat (one-on-one)
* Teachers + Students group chats per class
* Chatbot ↔ User conversations
* Cross-platform relay (e.g. Slack integration)

---

## 📖 License

MIT ©