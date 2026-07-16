# M5 usability validation protocol

## Release question

Can a site owner who does not read profiler internals capture one workflow, identify the intended top performance finding, explain the evidence in plain language, and state how they would verify a change?

## Participants

Recruit at least five participants from the intended v1 audience. Include at least three non-developers who operate WordPress sites and no more than two professional developers. Do not coach participants on flame graphs before the task.

## Fixture

Use a disposable WordPress site containing one intentionally slow, uniquely attributable database query or WordPress HTTP request. The expected owner, measured delay, and safe verification step must be written down before each session. Do not use production customer data.

## Task script

1. Ask the participant to find why the supplied page or workflow feels slow.
2. Allow them to use only the visible WP Flame interface and its linked documentation.
3. Ask them to describe what is slow, who owns it, how much observed time it contributes, how confident they are, and what they would do next.
4. Ask them to describe how they would collect evidence after making a change.
5. Record whether they used the top opportunity, technical timeline, detail panel, or comparison controls and where they hesitated.

## Success criteria

- At least four of five participants identify the intended top finding without facilitator guidance.
- At least four of five correctly name the measured owner and do not confuse inclusive child/parent time.
- Every participant can locate the verification instruction.
- No participant interprets missing or unavailable telemetry as proof of zero work.
- No participant describes a single before/after request as verified.

Any failure is an M8 release-candidate gate. Record participant role, completion, incorrect interpretations, time to finding, and the smallest interface or copy change likely to remove each failure. Do not record customer trace payloads or personal data in the result document.
