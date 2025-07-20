---
title: Configure Models
description: Documentation for installing Le Chat.
---

## Introduction
Le Chat allows you to make any model chattable, making it possible to have conversations with any model, and even any combination of models.

## Configure a Model for Chatting

First, prepare the models to send and receive messages. The models must use the `IsChatParticipant` trait. Also implement the `ChatParticipantInterface` interface in your models, which is a contract for all chat participants.

```diff lang="php" ins=" ChatParticipantInterface"
+use Mmedia\LeChat\Contracts\ChatParticipantInterface;
+use Mmedia\LeChat\Traits\IsChatParticipant;

class Teacher extends Model implements ChatParticipantInterface
{
+    use IsChatParticipant;

    // Your model code here
}

class Student extends Model implements ChatParticipantInterface
{
+    use IsChatParticipant;

    // Your model code here
}
```

Any model that implements the `ChatParticipantInterface` and uses the `IsChatParticipant` trait is now a chat participant. You can now send and receive messages between these models.